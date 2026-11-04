"""Back up and restore the acceptance media through real S3 operations, using a disposable key."""
import hashlib
import json
import os
import sys
import tempfile
import uuid
import boto3

if os.environ.get("DATABASE_URL") != "postgresql://postgres:lti-lab-only@db:5432/videoq":
    raise RuntimeError("Use only the isolated lab")
key = "media/" + sys.argv[1]
client = boto3.client("s3", endpoint_url=os.environ["R2_S3_ENDPOINT"], region_name="garage", aws_access_key_id=os.environ["R2_ACCESS_KEY_ID"], aws_secret_access_key=os.environ["R2_SECRET_ACCESS_KEY"])
restored = "acceptance-restore/" + str(uuid.uuid4())
try:
    with tempfile.NamedTemporaryFile() as backup:
        client.download_file("lti-sync", key, backup.name)
        def digest(path):
            value = hashlib.sha256()
            with open(path, "rb") as stream:
                for chunk in iter(lambda: stream.read(1024*1024), b""):
                    value.update(chunk)
            return value.hexdigest()
        expected = digest(backup.name)
        size = os.path.getsize(backup.name)
        client.upload_file(backup.name, "lti-sync", restored)
        with tempfile.NamedTemporaryFile() as check:
            client.download_file("lti-sync", restored, check.name)
            if digest(check.name) != expected or os.path.getsize(check.name) != size:
                raise RuntimeError("Restored media checksum differs")
        print(json.dumps({"bytes": size, "sha256": expected, "restoredChecksumMatches": True}))
finally:
    client.delete_object(Bucket="lti-sync", Key=restored)
