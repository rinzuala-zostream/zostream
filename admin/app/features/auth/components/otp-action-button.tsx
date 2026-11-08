"use client";

type OtpActionButtonProps = {
  onClick: () => void;
  disabled?: boolean;
  isLoading?: boolean;
  idleLabel: string;
  loadingLabel: string;
};

export function OtpActionButton({
  onClick,
  disabled = false,
  isLoading = false,
  idleLabel,
  loadingLabel,
}: OtpActionButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className="w-full rounded-xl border-2 border-blue-300 bg-blue-600 px-4 py-2.5 text-base font-semibold text-white shadow-md transition-all duration-200 ease-in-out hover:scale-[1.02] hover:bg-blue-800 hover:shadow-lg active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:scale-100 disabled:hover:bg-blue-600 dark:border-white/80"
    >
      {isLoading ? loadingLabel : idleLabel}
    </button>
  );
}
