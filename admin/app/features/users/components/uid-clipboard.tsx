"use client";

import Link from "next/link";
import { ClipboardPaste } from "lucide-react";
import { toast } from "react-toastify";

const STORAGE_KEY = "zostream_last_uid";

export function StoredLink({
  uid,
  href,
  className,
  children,
}: {
  uid: string;
  href: string;
  className?: string;
  children: React.ReactNode;
}) {
  const handleClick = () => {
    if (uid) {
      localStorage.setItem(STORAGE_KEY, uid);
    }
  };

  return (
    <Link href={href} className={className} onClick={handleClick}>
      {children}
    </Link>
  );
}

export function PasteUIDButton({
  onPaste,
}: {
  onPaste: (uid: string) => void;
}) {
  const handlePaste = (e: React.MouseEvent) => {
    e.preventDefault();
    const uid = localStorage.getItem(STORAGE_KEY);
    if (uid) {
      onPaste(uid);
      toast.info("UID pasted from cache");
    } else {
      toast.warn("No UID found in cache");
    }
  };

  return (
    <button
      type="button"
      onClick={handlePaste}
      className="flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-amber-700 transition hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-200 dark:hover:bg-amber-900/50"
    >
      <ClipboardPaste className="size-3" />
      Paste
    </button>
  );
}
