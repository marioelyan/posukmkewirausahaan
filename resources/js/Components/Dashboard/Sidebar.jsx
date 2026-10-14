import React from "react";
import { usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import { IconLayoutGrid } from "@tabler/icons-react";
import LinkItem from "@/Components/Dashboard/LinkItem";
import LinkItemDropdown from "@/Components/Dashboard/LinkItemDropdown";
import BusinessSwitcher from "@/Components/Dashboard/BusinessSwitcher";
import OutletSwitcher from "@/Components/Dashboard/OutletSwitcher";
import Menu from "@/Utils/Menu";
import { BRAND } from "@/config/brand";
import BrandLogo, { BrandMark } from "@/Components/BrandLogo";

const ROLE_LABEL_KEYS = {
    owner: "sidebar.roles.owner",
    manager: "sidebar.roles.manager",
    staff: "sidebar.roles.staff",
    "super-admin": "sidebar.roles.superAdmin",
};

export default function Sidebar({ sidebarOpen }) {
    const { t } = useTranslation();
    const { auth, storeProfile, appVersion, multiBusinessEnabled } = usePage().props;
    const menuNavigation = Menu();

    const roleKey = ROLE_LABEL_KEYS[auth?.businessRole];
    const roleLabel = roleKey ? t(roleKey) : "";

    const storeName = storeProfile?.name || BRAND.name;
    const storeLogo = storeProfile?.logo || null;
    const storeInitial =
        storeName?.charAt(0)?.toUpperCase() ||
        auth?.user?.name?.charAt(0)?.toUpperCase() ||
        "K";

    // The switchers render nothing when there is a single business/outlet, so the
    // mobile context block must not leave an empty strip behind.
    const hasBusinessContext =
        (auth?.businesses?.length ?? 0) >= 2 || Boolean(auth?.currentBusiness);
    const hasOutletContext =
        (auth?.outlets?.length ?? 0) >= 2 ||
        (multiBusinessEnabled && Boolean(auth?.currentOutlet));
    const showMobileContext = hasBusinessContext || hasOutletContext;

    return (
        <div
            data-role={auth?.businessRole ?? undefined}
            className={`
                ${sidebarOpen ? "translate-x-0 w-[260px]" : "-translate-x-full w-[260px]"}
                md:translate-x-0 ${sidebarOpen ? "md:w-[260px]" : "md:w-[80px]"}
                fixed md:relative inset-y-0 left-0 z-50 md:z-auto
                safe-x safe-bottom flex h-viewport flex-col overflow-hidden md:sticky md:top-0 md:self-stretch md:shrink-0
                border-r border-slate-200 dark:border-slate-800
                bg-white dark:bg-slate-900
                transition-all duration-300 ease-in-out
            `}
        >
            {/* Logo */}
            <div className="flex items-center justify-center h-16 border-b border-slate-100 dark:border-slate-800">
                {sidebarOpen ? (
                    <BrandLogo
                        logoSrc={storeLogo}
                        label={storeInitial}
                        name={storeName}
                        size={40}
                        nameClassName="text-lg"
                        className="min-w-0"
                    />
                ) : (
                    <BrandMark
                        logoSrc={storeLogo}
                        label={storeInitial}
                        size={36}
                        rounded="rounded-lg"
                    />
                )}
            </div>

            {/* Navigation */}
            <nav className="dashboard-scrollbar overscroll-contain-y min-h-0 flex-1 overflow-y-auto py-3">
                {/* Business/outlet context — the navbar drops these on phones, so
                    the drawer is where they live. Hidden from md up, where the
                    navbar shows them. */}
                {showMobileContext && (
                    <div className="mb-2 space-y-2 border-b border-slate-100 px-3 pb-3 md:hidden dark:border-slate-800">
                        <BusinessSwitcher
                            business={auth?.currentBusiness}
                            businesses={auth?.businesses}
                            locked={auth?.businessSwitchLocked}
                            fullWidth
                        />
                        <OutletSwitcher
                            outlet={auth?.currentOutlet}
                            outlets={auth?.outlets}
                            locked={auth?.outletLocked}
                            showIndicator={multiBusinessEnabled}
                            fullWidth
                        />
                    </div>
                )}

                {menuNavigation.map((section, index) => {
                    const hasPermission = section.details.some(
                        (detail) => detail.permissions === true
                    );
                    if (!hasPermission) return null;

                    // Quick action block: buttons above the sections, no title.
                    if (section.isActionBlock) {
                        return (
                            <div key={index} className="mb-2 space-y-2">
                                {section.details.map((detail, idx) => (
                                    <LinkItem
                                        key={idx}
                                        title={detail.title}
                                        icon={detail.icon}
                                        href={detail.href}
                                        access={detail.permissions}
                                        active={detail.active}
                                        sidebarOpen={sidebarOpen}
                                        variant={detail.variant}
                                    />
                                ))}
                            </div>
                        );
                    }

                    return (
                        <div key={index} className="mb-2">
                            {/* Section Title */}
                            {sidebarOpen && (
                                <div className="px-4 py-2">
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-[rgb(var(--role-accent-600))] dark:text-[rgb(var(--role-accent-400))]">
                                        {section.title}
                                    </span>
                                </div>
                            )}

                            {/* Menu Items */}
                            <div
                                className={
                                    sidebarOpen
                                        ? ""
                                        : "flex flex-col items-center"
                                }
                            >
                                {section.details.map((detail, idx) => {
                                    if (!detail.permissions) return null;

                                    if (detail.hasOwnProperty("subdetails")) {
                                        return (
                                            <LinkItemDropdown
                                                key={idx}
                                                title={detail.title}
                                                icon={detail.icon}
                                                data={detail.subdetails}
                                                access={detail.permissions}
                                                sidebarOpen={sidebarOpen}
                                            />
                                        );
                                    }

                                    return (
                                        <LinkItem
                                            key={idx}
                                            title={detail.title}
                                            icon={detail.icon}
                                            href={detail.href}
                                            access={detail.permissions}
                                            active={detail.active}
                                            sidebarOpen={sidebarOpen}
                                            variant={detail.variant}
                                        />
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </nav>

            {/* Version/Footer */}
            {sidebarOpen && (
                <div className="p-4 border-t border-slate-100 dark:border-slate-800">
                    {roleLabel && (
                        <div className="mb-2 flex justify-center">
                            <span className="inline-flex items-center rounded-full bg-[rgb(var(--role-accent-50))] px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[rgb(var(--role-accent-700))] dark:bg-[rgb(var(--role-accent-950)/0.5)] dark:text-[rgb(var(--role-accent-300))]">
                                {roleLabel}
                            </span>
                        </div>
                    )}
                    <p className="text-[10px] text-slate-400 dark:text-slate-600 text-center">
                        {BRAND.name} {appVersion}
                    </p>
                    <p className="mt-0.5 text-[10px] text-slate-300 dark:text-slate-700 text-center">
                        {BRAND.tagline}
                    </p>
                </div>
            )}
        </div>
    );
}
