import { Message } from "@/lib/api";

type Props = {
  message: Message;
  childrenBelow?: React.ReactNode; // สำหรับ stars + badge
};

export function ChatBubble({ message, childrenBelow }: Props) {
  const isUser = message.role === "user";

  return (
    <div className={`flex mb-2 ${isUser ? "justify-end" : "justify-start"}`}>
      <div
        className={`max-w-[80%] flex flex-col ${isUser ? "items-end" : "items-start"}`}
      >
        <div
          className={`px-3 py-2 rounded-2xl text-sm whitespace-pre-wrap break-words
          ${
            isUser
              ? "bg-blue-500 text-white rounded-br-sm"
              : "bg-muted text-foreground rounded-bl-sm"
          }`}
        >
          {message.content}
        </div>
        {childrenBelow}
      </div>
    </div>
  );
}
