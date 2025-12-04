"use client";

type Props = {
  onSelectFiles: (files: File[]) => void;
};

export function UploadDropzone({ onSelectFiles }: Props) {
  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    const files = Array.from(e.dataTransfer.files || []);
    if (files.length > 0) onSelectFiles(files);
  };

  return (
    <div
      onDragOver={(e) => e.preventDefault()}
      onDrop={handleDrop}
      className="border-2 border-dashed rounded-xl p-6 text-center text-sm cursor-pointer bg-muted/30 hover:bg-muted/50 transition"
      onClick={() => document.getElementById("file-input")?.click()}
    >
      <p>ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</p>
      <input
        type="file"
        id="file-input"
        className="hidden"
        multiple
        onChange={(e) => {
          const files = Array.from(e.target.files || []);
          if (files.length > 0) onSelectFiles(files);
        }}
      />
    </div>
  );
}
