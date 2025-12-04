type Props = {
  summary: string | null;
};

export function SummaryPanel({ summary }: Props) {
  if (!summary) return null;

  return (
    <div className="bg-muted rounded-lg p-3 text-sm whitespace-pre-wrap">
      {summary}
    </div>
  );
}
