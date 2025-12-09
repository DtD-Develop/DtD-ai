"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

let echoInstance: any = null;

export function getEcho(): any | null {
  if (typeof window === "undefined") return null;

  if (!echoInstance) {
    window.Pusher = Pusher;

    echoInstance = new Echo({
      broadcaster: "pusher",
      key: process.env.NEXT_PUBLIC_REVERB_KEY,
      wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
      wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT || 80),
      forceTLS: false,
      encrypted: false,
      disableStats: true,
    });
  }

  return echoInstance;
}
