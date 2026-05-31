FROM mcr.microsoft.com/playwright/python:v1.60.0-noble

WORKDIR /app

COPY wrapper.py .

# Work directory for run.json / result.json / artifacts (bind-mounted at runtime)
RUN mkdir -p /work/artifacts

ENV RUN_JSON_PATH=/work/run.json
ENV RESULT_JSON_PATH=/work/result.json
ENV ARTIFACTS_DIR=/work/artifacts

CMD ["python", "wrapper.py"]
