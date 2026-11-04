import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
const dir = process.argv[2] || '/tmp/videoq-lti-lab';
const moodle = JSON.parse(readFileSync(join(dir, 'moodle-fixture.json'), 'utf8'));
const videoq = JSON.parse(readFileSync(join(dir, 'videoq-fixture.json'), 'utf8'));
const config = {
  toolOrigin: 'https://videoq.localhost:18443',
  registrations: [{
    id: 'moodle-lab', issuer: moodle.issuer, clientId: moodle.clientId,
    authorizationUrl: moodle.issuer + '/mod/lti/auth.php', jwksUrl: moodle.issuer + '/mod/lti/certs.php',
    courseAccess: "moodle",
    frameOrigins: [new URL(moodle.issuer).origin],
    resources: ['VideoQ questions', 'VideoQ new window'].map(name => ({
      deploymentId: moodle.deploymentId, contextId: moodle.contextId,
      resourceLinkId: moodle.activities[name].resourceLinkId, courseId: videoq.courseId, videoId: videoq.videoId,
    })),
  }],
};
writeFileSync(join(dir, 'lti-config.json'), JSON.stringify(config, null, 2));
console.log('LTI resource bindings configured from actual Moodle identifiers');
