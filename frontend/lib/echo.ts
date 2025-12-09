"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

if (typeof window !== "undefined") {
  window.Pusher = Pusher;
}

const echo = new Echo({
  broadcaster: "pusher",
  key: process.env.NEXT_PUBLIC_REVERB_KEY,
  wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
  wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT || 80),
  forceTLS: false,
  encrypted: false,
  disableStats: true,
});

export default echo;
