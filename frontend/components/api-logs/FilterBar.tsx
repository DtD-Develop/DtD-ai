"use client";

type Props = {
  q: string;
  onQChange: (v: string) => void;
  method: string;
  onMethodChange: (v: string) => void;
  statusGroup: string;
  onStatusGroupChange: (v: string) => void;
  from: string;
  to: string;
  onFromChange: (v: string) => void;
  onToChange: (v: string) => void;
  onSubmit: () => void;
};

export function FilterBar(props: Props) {
  const {
    q,
    onQChange,
    method,
    onMethodChange,
    statusGroup,
    onStatusGroupChange,
    from,
    to,
    onFromChange,
    onToChange,
    onSubmit,
  } = props;

  return (
    <div className="flex flex-wrap gap-2 items-end mb-3">
      <div className="flex flex-col">
        <label className="text-[10px] text-muted-foreground">Search</label>
        <input
          className="border rounded px-2 py-1 text-xs"
          placeholder="/api/chat"
          value={q}
          onChange={(e) => onQChange(e.target.value)}
        />
      </div>

      <div className="flex flex-col">
        <label className="text-[10px] text-muted-foreground">Method</label>
        <select
          className="border rounded px-2 py-1 text-xs"
          value={method}
          onChange={(e) => onMethodChange(e.target.value)}
        >
          <option value="">All</option>
          <option value="GET">GET</option>
          <option value="POST">POST</option>
          <option value="PUT">PUT</option>
          <option value="PATCH">PATCH</option>
          <option value="DELETE">DELETE</option>
        </select>
      </div>

      <div className="flex flex-col">
        <label className="text-[10px] text-muted-foreground">Status</label>
        <select
          className="border rounded px-2 py-1 text-xs"
          value={statusGroup}
          onChange={(e) => onStatusGroupChange(e.target.value)}
        >
          <option value="">All</option>
          <option value="2xx">2xx</option>
          <option value="4xx">4xx</option>
          <option value="5xx">5xx</option>
        </select>
      </div>

      <div className="flex flex-col">
        <label className="text-[10px] text-muted-foreground">From</label>
        <input
          type="date"
          className="border rounded px-2 py-1 text-xs"
          value={from}
          onChange={(e) => onFromChange(e.target.value)}
        />
      </div>

      <div className="flex flex-col">
        <label className="text-[10px] text-muted-foreground">To</label>
        <input
          type="date"
          className="border rounded px-2 py-1 text-xs"
          value={to}
          onChange={(e) => onToChange(e.target.value)}
        />
      </div>

      <button
        className="ml-auto px-3 py-1 rounded bg-blue-500 text-white text-xs"
        onClick={onSubmit}
      >
        Apply
      </button>
    </div>
  );
}
