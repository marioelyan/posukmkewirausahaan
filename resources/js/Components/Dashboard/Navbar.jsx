import React from "react";
import { usePage } from "@inertiajs/react";
import {
    IconMenu2,
    IconMoon,
    IconSun,
    IconSearch,
    IconQuestionMark,
} from "@tabler/icons-react";
import AuthDropdown from "@/Components/Dashboard/AuthDropdown";
import LanguageSwitcher from "@/Components/Dashboard/LanguageSwitcher";
import OutletSwitcher from "@/Components/Dashboard/OutletSwitcher";
import BusinessSwitcher from "@/Components/Dashboard/BusinessSwitcher";
import Menu from "@/Utils/Menu";
import Notification from "@/Components/Dashboard/Notification";
import { useTour } from "@/Hooks/useTour";
import i18n from "@/i18n";
import { BRAND } from "@/config/brand";
import { BrandMark } from "@/Components/BrandLogo";

export default function Navbar({ toggleSidebar, themeSwitcher, darkMode }) {
    const { auth, storeProfile, multiBusinessEnabled } = usePage().props;
    const { start: startTour, isActive: tourActive } = useTour("dashboard");
    const menuNavigation = Menu();

    const storeName = storeProfile?.name || BRAND.name;
    const storeInitial = storeName?.charAt(0)?.toUpperCase() || "K";

    // Get current page title
    const links = menuNavigation.flatMap((item) => item.details);
    const sublinks = links
        .filter((item) => item.hasOwnProperty("subdetails"))
        .flatMap((item) => item.subdetails);

    const getCurrentTitle = () => {
        for (const link of links) {
            if (link.hasOwnProperty("subdetails")) {
                const activeSublink = sublinks.find((s) => s.active);
                if (activeSublink) return activeSublink.title;
            } else if (link.active) {
                return link.title;
            }
        }
        return "Dashboard";
    };

    return (
        <header
            className="sticky top-0 z-30 h-16 flex items-center justify-between gap-3 px-4 md:px-6
            bg-white dark:bg-slate-900
            border-b border-slate-200 dark:border-slate-800
            transition-colors duration-200"
        >
            {/* Left Section */}
            <div className="flex min-w-0 items-center gap-4">
                {/* Sidebar Toggle */}
                <button
                    onClick={toggleSidebar}
                    aria-label="Toggle Sidebar"
                    className="flex p-2.5 rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                >
                    <IconMenu2 size={20} strokeWidth={1.5} />
                </button>

                {/* Mobile Logo — the store name only fits once the viewport can
                    spare the room, so it is hidden below `sm` and truncated. */}
                <div className="md:hidden flex min-w-0 items-center gap-2">
                    <BrandMark
                        size={28}
                        label={storeInitial}
                        rounded="rounded-lg"
                    />
                    <span className="hidden sm:block truncate text-lg font-bold text-slate-800 dark:text-white">
                        {storeName}
                    </span>
                </div>

                {/* Current Page Title */}
                <div className="hidden md:flex items-center">
                    <div className="w-px h-6 bg-slate-200 dark:bg-slate-700 mr-4" />
                    <h1 className="text-base font-semibold text-slate-800 dark:text-slate-200">
                        {getCurrentTitle()}
                    </h1>
                </div>
            </div>

            {/* Right Section — business/outlet context needs a wide screen, so it
                moves into the sidebar drawer on phones. Everything that stays is
                either an icon button or the account menu. */}
            <div className="flex min-w-0 items-center gap-1.5 sm:gap-2">
                <div className="hidden md:flex items-center gap-2">
                    <BusinessSwitcher business={auth?.currentBusiness} businesses={auth?.businesses} locked={auth?.outletLocked} />
                    <OutletSwitcher outlet={auth?.currentOutlet} outlets={auth?.outlets} locked={auth?.outletLocked} showIndicator={multiBusinessEnabled} />
                    {/* Tour Guide */}
                    <button
                        onClick={startTour}
                        disabled={tourActive}
                        className="p-2.5 rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                        title={i18n.t("tour.button")}
                    >
                        <IconQuestionMark size={20} strokeWidth={1.5} />
                    </button>
                </div>

                {/* Language Switcher */}
                <LanguageSwitcher />

                {/* Theme Toggle */}
                <button
                    onClick={themeSwitcher}
                    aria-label={darkMode ? "Light Mode" : "Dark Mode"}
                    className="p-2.5 rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40"
                    title={darkMode ? "Light Mode" : "Dark Mode"}
                >
                    {darkMode ? (
                        <IconSun
                            size={20}
                            strokeWidth={1.5}
                            className="text-warning-600 dark:text-warning-400"
                        />
                    ) : (
                        <IconMoon size={20} strokeWidth={1.5} />
                    )}
                </button>

                {/* Notifications */}
                <Notification />

                {/* Divider */}
                <div className="hidden sm:block w-px h-8 bg-slate-200 dark:bg-slate-700 mx-1" />

                {/* User Dropdown */}
                <AuthDropdown auth={auth} />
            </div>
        </header>
    );
}
