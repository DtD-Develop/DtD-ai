<?php

return [
    // คะแนนขั้นต่ำที่ถือว่า "ดีพอ" สำหรับใช้ train เข้า KB
    "train_min_score" => env("DTD_TRAIN_MIN_SCORE", 4),

    // โฟลเดอร์เก็บไฟล์ที่สร้างจาก chat-train
    "chat_train_dir" => "kb-chat-train",
];
