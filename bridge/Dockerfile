FROM python:3.12-slim

WORKDIR /app

# Install deps first for layer caching.
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# App code only (tests stay in the repo; they are not part of the runtime image).
COPY app.py litter_manager.py normalizer.py ./

# Default when compose does not pass BRIDGE_VERSION. make bump-patch rewrites it.
ARG BRIDGE_VERSION=0.4.2
ENV PORT=8080
ENV BRIDGE_VERSION=${BRIDGE_VERSION}
EXPOSE 8080

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8080"]
