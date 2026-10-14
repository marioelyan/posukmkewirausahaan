import { useState } from "react";
import { Link } from "@inertiajs/react";
import {
    IconBrandGithub,
    IconStar,
    IconArrowRight,
    IconMenu2,
    IconX,
} from "@tabler/icons-react";
import { BRAND } from "@/config/brand";
import { BrandMark } from "@/Components/BrandLogo";

const GITHUB_URL = BRAND.github;
const DOCS_URL = BRAND.docs;

export const NAV_LINKS = [
    { label: "Fitur", href: "/fitur" },
    { label: "Dokumentasi", href: "/dokumentasi" },
    { label: "Roadmap", href: "/roadmap" },
    { label: "Kontribusi", href: "/kontribusi" },
];

export default function PublicLayout({ children, active = "" }) {
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col">
            {/* ============ NAVBAR ============ */}
            <nav className="safe-top safe-x fixed top-0 left-0 right-0 z-50 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border-b border-slate-200 dark:border-slate-800">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
                    <Link href="/" className="flex min-w-0 items-center gap-2.5">
                        <BrandMark size={36} rounded="rounded-xl" />
                        <span className="truncate text-lg font-bold text-slate-900 dark:text-white">
                            {BRAND.name}
                        </span>
                    </Link>

                    <div className="hidden md:flex items-center gap-7">
                        {NAV_LINKS.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className={`text-sm transition-colors ${
                                    active === link.href
                                        ? "text-primary-600 dark:text-primary-400 font-semibold"
                                        : "text-slate-600 dark:text-slate-400 hover:text-primary-500"
                                }`}
                            >
                                {link.label}
                            </Link>
                        ))}
                    </div>

                    <div className="flex items-center gap-2 sm:gap-3">
                        <a
                            href={GITHUB_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="hidden sm:flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 border border-slate-200 dark:border-slate-700 rounded-xl hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
                        >
                            <IconStar size={15} className="text-warning-400" />
                            Star
                        </a>
                        <Link
                            href="/login"
                            className="px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:text-primary-500 transition-colors sm:px-5"
                        >
                            Masuk
                        </Link>
                        <a
                            href={GITHUB_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-primary-500 to-primary-600 rounded-xl hover:from-primary-600 hover:to-primary-700 shadow-lg shadow-primary-500/25 transition-all"
                        >
                            Get Source
                        </a>

                        {/* Mobile menu toggle — nav links are otherwise unreachable on phones */}
                        <button
                            type="button"
                            onClick={() => setMenuOpen((open) => !open)}
                            aria-label={menuOpen ? "Tutup menu" : "Buka menu"}
                            aria-expanded={menuOpen}
                            className="md:hidden flex min-h-touch min-w-touch items-center justify-center rounded-xl text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 transition-colors"
                        >
                            {menuOpen ? <IconX size={22} /> : <IconMenu2 size={22} />}
                        </button>
                    </div>
                </div>

                {/* Mobile menu panel */}
                {menuOpen && (
                    <div className="md:hidden border-t border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="px-4 py-3 sm:px-6">
                            <nav className="flex flex-col">
                                {NAV_LINKS.map((link) => (
                                    <Link
                                        key={link.href}
                                        href={link.href}
                                        onClick={() => setMenuOpen(false)}
                                        className={`min-h-touch flex items-center rounded-xl px-3 text-sm font-medium transition-colors ${
                                            active === link.href
                                                ? "text-primary-600 dark:text-primary-400"
                                                : "text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                                        }`}
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </nav>
                            <div className="mt-3 flex flex-col gap-2 border-t border-slate-200 pt-3 dark:border-slate-800">
                                <a
                                    href={GITHUB_URL}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={() => setMenuOpen(false)}
                                    className="min-h-touch flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-medium text-slate-700 dark:border-slate-700 dark:text-slate-300"
                                >
                                    <IconBrandGithub size={16} />
                                    GitHub
                                </a>
                                <a
                                    href={GITHUB_URL}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={() => setMenuOpen(false)}
                                    className="min-h-touch flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-primary-500 to-primary-600 px-4 text-sm font-semibold text-white"
                                >
                                    Get Source
                                    <IconArrowRight size={16} />
                                </a>
                            </div>
                        </div>
                    </div>
                )}
            </nav>

            {/* ============ CONTENT ============ */}
            <main className="flex-1 pt-public-nav">{children}</main>

            {/* ============ FOOTER ============ */}
            <footer className="safe-bottom safe-x py-10 px-6 border-t border-slate-200 dark:border-slate-800">
                <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-6">
                    <div className="flex items-center gap-3">
                        <BrandMark size={32} rounded="rounded-lg" />
                        <div>
                            <div className="font-semibold text-slate-700 dark:text-slate-300">
                                {BRAND.name}
                            </div>
                            <div className="text-xs text-slate-500">
                                {BRAND.tagline}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-6 text-sm text-slate-500 dark:text-slate-400">
                        <Link href="/fitur" className="hover:text-primary-500 transition-colors">
                            Fitur
                        </Link>
                        <Link href="/dokumentasi" className="hover:text-primary-500 transition-colors">
                            Dokumentasi
                        </Link>
                        <Link href="/roadmap" className="hover:text-primary-500 transition-colors">
                            Roadmap
                        </Link>
                        <Link href="/kontribusi" className="hover:text-primary-500 transition-colors">
                            Kontribusi
                        </Link>
                        <a href={GITHUB_URL} target="_blank" rel="noopener noreferrer" className="hover:text-primary-500 transition-colors">
                            GitHub
                        </a>
                        <a href={`${GITHUB_URL}/blob/main/LICENSE`} target="_blank" rel="noopener noreferrer" className="hover:text-primary-500 transition-colors">
                            Lisensi {BRAND.license}
                        </a>
                    </div>

                    <p className="text-sm text-slate-500">
                        © {new Date().getFullYear()} Dibuat oleh {BRAND.credit}
                    </p>
                </div>
            </footer>
        </div>
    );
}
