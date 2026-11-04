"""Consistent PostgreSQL snapshots restored to disposable databases and removed."""
from pathlib import Path
import os
import subprocess
import uuid
from psycopg import sql
from videoq_lab.runtime import DATA, DB_CONTAINER, database, guard_lab, save_report

TABLES = {'videoq': ['users', 'videos', 'video_courses', 'video_course_members', 'scene_embeddings',
                     'lms_resources', 'lms_courses', 'chat_logs'],
          'moodle': ['mdl_course', 'mdl_course_modules', 'mdl_files', 'mdl_local_videoq_sync']}

def counts(connection, tables):
    return {table: connection.execute(sql.SQL('SELECT count(*) AS n FROM {}').format(sql.Identifier(table))).fetchone()['n'] for table in tables}

def main():
    guard_lab()
    target = DATA / 'acceptance' / 'backups'
    target.mkdir(parents=True, exist_ok=True, mode=0o700)
    results = []
    with database('postgres') as admin:
        for name, tables in TABLES.items():
            restored = 'videoq_restore_' + uuid.uuid4().hex
            path = target / (name + '.dump')
            with database(name, autocommit=False) as source:
                source.execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY')
                snapshot = source.execute('SELECT pg_export_snapshot() AS id').fetchone()['id']
                expected = counts(source, tables)
                fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
                os.fchmod(fd, 0o600)
                with os.fdopen(fd, 'wb') as output:
                    subprocess.run(['sudo', '-n', 'docker', 'exec', DB_CONTAINER, 'pg_dump', '-U', 'postgres', '-d', name,
                                    '-Fc', '--snapshot=' + snapshot], stdout=output, stderr=subprocess.PIPE, check=True, timeout=90)
            admin.execute(sql.SQL('CREATE DATABASE {}').format(sql.Identifier(restored)))
            try:
                with path.open('rb') as source:
                    subprocess.run(['sudo', '-n', 'docker', 'exec', '-i', DB_CONTAINER, 'pg_restore', '-U', 'postgres',
                                    '--no-owner', '--no-privileges', '-d', restored], stdin=source,
                                   stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True, timeout=90)
                with database(restored) as check:
                    assert counts(check, tables) == expected, 'Restored row counts differ'
                    assert check.execute("SELECT count(*) AS n FROM pg_constraint WHERE contype='f' AND NOT convalidated").fetchone()['n'] == 0
                results.append(dict(database=name, bytes=path.stat().st_size, counts=expected, isolatedRestore=True))
            finally:
                admin.execute(sql.SQL('DROP DATABASE {} WITH (FORCE)').format(sql.Identifier(restored)))
    save_report('database-backup', dict(snapshotsConsistent=True, privateFiles=True, results=results, temporaryDatabasesRemoved=True))

if __name__ == '__main__':
    main()
