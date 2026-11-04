"""Load the isolated worker's provider secret without putting it in argv or logs."""
import json
import os
import runpy

if os.environ.get("DATABASE_URL") != "postgresql://postgres:lti-lab-only@db:5432/videoq":
    raise RuntimeError("Use only the isolated lab")
with open("/run/secrets/videoq-provider.json", encoding="utf-8") as stream:
    config = json.load(stream)
if not isinstance(config.get("apiKey"), str) or len(config["apiKey"]) < 20:
    raise RuntimeError("Invalid private provider configuration")
os.environ.update({
    "OPENROUTER_API_KEY": config["apiKey"],
    "WHISPER_BACKEND": "openrouter",
    "EMBEDDING_PROFILE": "openai",
    "EMBEDDING_BASE_URL": config["baseUrl"],
    "EMBEDDING_API_KEY": config["apiKey"],
    "EMBEDDING_MODEL": config["embeddingModel"],
    "PGVECTOR_COLLECTION_NAME": "scene_embeddings",
})
runpy.run_path("/app/scripts/run_worker.py", run_name="__main__")
