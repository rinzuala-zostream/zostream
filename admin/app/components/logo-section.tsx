import Image from "next/image";

type LogoSectionProps = {
  className?: string;
  initialMode?: "light" | "dark";
};

export function LogoSection({ className = "" }: LogoSectionProps) {
  return (
    <div className={`${className}`}>
      <div className="flex items-center justify-center gap-2 sm:gap-3">
        <Image
          src="/logo/logo.jpg"
          alt="Zo Stream"
          width={38}
          height={38}
          className="h-7 w-7 rounded-md sm:h-8 sm:w-8 sm:rounded-lg md:h-9 md:w-9"
          priority
        />
        <span className="whitespace-nowrap text-xl font-semibold tracking-tight text-slate-900 dark:text-white sm:text-sm md:text-4xl">
          Zo Stream Admin
        </span>
      </div>
    </div>
  );
}
