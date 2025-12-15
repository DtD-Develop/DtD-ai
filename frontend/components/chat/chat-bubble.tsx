import React, { FC, ReactNode } from "react";
import { cn } from "@/lib/utils";

type Props = {
  message: {
    id: number;
    role: "user" | "assistant";
    content: string;
  };
  isStreaming?: boolean;
  children?: ReactNode; // rating stars, etc.
};

export const ChatBubble: FC<Props> = ({ message, isStreaming, children }) => {
  const isAssistant = message.role === "assistant";

  const isLoading =
    isAssistant &&
    (isStreaming || !message.content || message.content.trim() === "");

  return (
    <div
      className={cn(
        "w-full mb-4 flex",
        isAssistant ? "justify-start" : "justify-end",
      )}
    >
      <div
        className={cn(
          "max-w-[85%] rounded-xl px-4 py-3 shadow-sm border",
          isAssistant
            ? "bg-white text-gray-900 border-gray-200"
            : "bg-blue-600 text-white border-blue-600",
        )}
      >
        {/* USER TEXT */}
        {!isAssistant && (
          <div className="whitespace-pre-wrap break-words text-sm">
            {message.content}
          </div>
        )}

        {/* ASSISTANT LOADING */}
        {isAssistant && isLoading && (
          <div className="flex items-center gap-2 py-1">
            <TypingDots />
          </div>
        )}

        {/* ASSISTANT TEXT */}
        {isAssistant && !isLoading && (
          <div className="whitespace-pre-wrap break-words text-sm leading-relaxed">
            {message.content}
          </div>
        )}

        {/* Extra children (rating stars) */}
        {children && (
          <div className="mt-2 border-t pt-2 border-gray-200">{children}</div>
        )}
      </div>
    </div>
  );
};

function TypingDots() {
  return (
    <div className="flex items-center space-x-1">
      <span className="h-2 w-2 rounded-full bg-gray-400 animate-bounce [animation-delay:-0.3s]" />
      <span className="h-2 w-2 rounded-full bg-gray-400 animate-bounce [animation-delay:-0.15s]" />
      <span className="h-2 w-2 rounded-full bg-gray-400 animate-bounce" />
    </div>
  );
}

export function SkeletonBlock() {
  return (
    <div className="w-full">
      <div className="animate-pulse flex flex-col gap-2">
        <div className="h-3 w-4/5 bg-gray-200 rounded"></div>
        <div className="h-3 w-3/5 bg-gray-200 rounded"></div>
        <div className="h-3 w-2/5 bg-gray-200 rounded"></div>
      </div>
    </div>
  );
}
