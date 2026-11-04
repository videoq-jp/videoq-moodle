"""Check physical media cleanup in the isolated lab, without printing credentials."""
import json
import os
import sys

import boto3
from botocore.exceptions import ClientError

if os.environ.get("DATABASE_URL") != "postgresql://postgres:lti-lab-only@db:5432/videoq":
    raise RuntimeError("Use only the isolated LTI lab")
client = boto3.client(
    "s3", endpoint_url=os.environ["R2_S3_ENDPOINT"], region_name="garage",
    aws_access_key_id=os.environ["R2_ACCESS_KEY_ID"], aws_secret_access_key=os.environ["R2_SECRET_ACCESS_KEY"],
)
try:
    client.head_object(Bucket="lti-sync", Key="media/" + sys.argv[1])
    exists = True
except ClientError as error:
    if str(error.response["Error"]["Code"]) not in {"404", "NoSuchKey", "NotFound"}:
        raise
    exists = False
print(json.dumps({"exists": exists}))
