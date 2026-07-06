from fastapi import FastAPI

app = FastAPI()

@app.get("/")
def home():
    return {"message": "Kulsah AI Service Running"}

@app.post("/recommend")
def recommend(data: dict):
    return {
        "user_id": data["user_id"],
        "videos": [
            {"video_id": 1, "score": 0.95},
            {"video_id": 2, "score": 0.91}
        ]
    }