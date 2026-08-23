# Local development image for services/ai (FastAPI AI Gateway).
# NOT a production image — see infrastructure/docker/platform.Dockerfile note.

FROM python:3.12-slim-bookworm

WORKDIR /srv/ai

COPY services/ai/requirements.txt services/ai/requirements-dev.txt ./
RUN pip install --no-cache-dir -r requirements.txt -r requirements-dev.txt

EXPOSE 8100

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100", "--reload"]
