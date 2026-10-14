import React, { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import Sidebar from "@/Components/Dashboard/Sidebar";
import Navbar from "@/Components/Dashboard/Navbar";
import { Toaster } from "react-hot-toast";
import { useTheme } from "@/Context/ThemeSwitcherContext";

export default function AppLayout({ children }) {
    const { darkMode, themeSwitcher } = useTheme();
    const { url } = usePage();
    const { auth, security } = usePage().props;

    const getInitialSidebarState = () => {
        if (typeof window === "undefined") return false;
        const stored = localStorage.getItem("sidebarOpen");
        if (stored !== null) return stored === "true";
        return window.innerWidth >= 768;
    };

    const [sidebarOpen, setSidebarOpen] = useState(getInitialSidebarState);
    const [isMobile, setIsMobile] = useState(
        typeof window !== "undefined" ? window.innerWidth < 768 : false
    );

    useEffect(() => {
        localStorage.setItem("sidebarOpen", sidebarOpen);
    }, [sidebarOpen]);

    // Auto-close sidebar on mobile after navigation
    useEffect(() => {
        if (isMobile) {
            setSidebarOpen(false);
        }
    }, [url]);

    useEffect(() => {
        const handleResize = () => {
            const mobile = window.innerWidth < 768;
            setIsMobile(mobile);
            if (mobile) {
                setSidebarOpen(false);
            }
        };

        window.addEventListener("resize", handleResize);
        return () => window.removeEventListener("resize", handleResize);
    }, []);

    const toggleSidebar = () => setSidebarOpen(!sidebarOpen);
    const securityWarnings = security?.warnings ?? [];
    const showSecurityWarnings =
        auth?.super === true && securityWarnings.length > 0;

    return (
        <div className="flex h-viewport overflow-hidden bg-slate-100 dark:bg-slate-950 transition-colors duration-200">
            <Sidebar sidebarOpen={sidebarOpen} />
            {/* Mobile overlay — above the sticky header (z-30) so the open drawer
                dims the whole shell instead of leaving the header lit on top. */}
            <div
                className={`fixed inset-0 bg-slate-900/40 md:hidden transition-opacity duration-300 ${
                    sidebarOpen ? "opacity-100 pointer-events-auto z-40" : "opacity-0 pointer-events-none"
                }`}
                onClick={() => setSidebarOpen(false)}
            />
            <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                <Navbar
                    toggleSidebar={toggleSidebar}
                    themeSwitcher={themeSwitcher}
                    darkMode={darkMode}
                />
                <main className="dashboard-scrollbar safe-bottom overscroll-contain-y flex-1 overflow-y-auto">
                    <div className="w-full px-4 py-6 md:px-6 lg:px-8">
                        {showSecurityWarnings && (
                            <div className="mb-6 rounded-2xl border border-warning-200 bg-warning-50 px-4 py-4 text-warning-800 dark:border-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                                <p className="text-sm font-semibold">
                                    Production security baseline warning
                                </p>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {securityWarnings.map((warning) => (
                                        <li key={warning.key}>
                                            - {warning.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        <Toaster
                            position="top-right"
                            containerStyle={{
                                top: "calc(16px + env(safe-area-inset-top, 0px))",
                                bottom: "calc(16px + env(safe-area-inset-bottom, 0px))",
                            }}
                            toastOptions={{
                                className: "text-sm",
                                duration: 3000,
                                style: {
                                    background: darkMode ? "#1e293b" : "#fff",
                                    color: darkMode ? "#f1f5f9" : "#1e293b",
                                    border: `1px solid ${
                                        darkMode ? "#334155" : "#e2e8f0"
                                    }`,
                                    borderRadius: "12px",
                                },
                            }}
                        />
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
