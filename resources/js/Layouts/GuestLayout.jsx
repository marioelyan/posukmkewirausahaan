import BrandLogo from "@/Components/BrandLogo";
import { Link } from "@inertiajs/react";

export default function Guest({ children }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 py-10 transition-colors duration-200 dark:bg-slate-950">
            <Link href="/" className="mb-6">
                <BrandLogo size={48} nameClassName="text-2xl" />
            </Link>

            <div className="w-full overflow-hidden rounded-2xl border border-slate-200 bg-white px-6 py-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                {children}
            </div>
        </div>
    );
}
