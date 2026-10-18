FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt && useradd --uid 10001 --create-home collector
COPY importer.py .
USER 10001:10001
ENTRYPOINT ["python", "importer.py"]
CMD ["preview"]
