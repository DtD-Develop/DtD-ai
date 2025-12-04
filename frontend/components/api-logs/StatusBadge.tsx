type Props = { status: number | null };

export function StatusBadge({ status }: Props) {
  if (!status) return <span className="text-xs text-muted-foreground">-</span>;

  let color = "bg-slate-600/20 text-slate-300 border-slate-500/60";
  if (status >= 200 && status < 300)
    color = "bg-emerald-500/15 text-emerald-400 border-emerald-500/40";
  else if (status >= 400 && status < 500)
    color = "bg-amber-500/15 text-amber-300 border-amber-500/40";
  else if (status >= 500)
    color = "bg-rose-500/15 text-rose-300 border-rose-500/40";

  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium ${color}`}
    >
      {status}
    </span>
  );
}
