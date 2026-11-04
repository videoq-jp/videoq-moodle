"""Create/delete only a disposable queue on the isolated ElasticMQ server."""
import json
import os
import re
import sys
from urllib.parse import urlsplit, urlunsplit
import boto3
if os.environ.get('DATABASE_URL') != 'postgresql://postgres:lti-lab-only@db:5432/videoq' or not re.fullmatch(r'[a-f0-9]{32}', sys.argv[2]):
    raise RuntimeError('Isolated lab only')
source = urlsplit(os.environ['SQS_QUEUE_URL'])
if source.hostname != 'queue':
    raise RuntimeError('Only the lab queue server is allowed')
endpoint = urlunsplit((source.scheme, source.netloc, '', '', ''))
client = boto3.client('sqs', endpoint_url=endpoint, region_name=os.environ.get('AWS_REGION', 'us-east-1'),
                      aws_access_key_id='lab', aws_secret_access_key='lab')
name = 'videoq-pull-spike-' + sys.argv[2]
if sys.argv[1] == 'create':
    raw = urlsplit(client.create_queue(QueueName=name)['QueueUrl'])
    url = urlunsplit((source.scheme, source.netloc, raw.path, '', ''))
    print(json.dumps({'url': url}))
elif sys.argv[1] == 'delete':
    raw = urlsplit(client.get_queue_url(QueueName=name)['QueueUrl'])
    client.delete_queue(QueueUrl=urlunsplit((source.scheme, source.netloc, raw.path, '', '')))
    print(json.dumps({'removed': True}))
else:
    raise RuntimeError('Unsupported action')
