import { usePage } from "@inertiajs/react";
import { useTranslation } from "react-i18next";
import {
    IconBooks,
    IconBox,
    IconCategory,
    IconChartArrowsVertical,
    IconChartBar,
    IconChartBarPopular,
    IconChartInfographic,
    IconCirclePlus,
    IconClockHour6,
    IconClipboardCheck,
    IconCreditCard,
    IconCrown,
    IconFileCertificate,
    IconFileDescription,
    IconFolder,
    IconGift,
    IconKey,
    IconLayout2,
    IconBuildingStore,
    IconSchool,
    IconShoppingCart,
    IconTable,
    IconUserBolt,
    IconUserShield,
    IconUserSquare,
    IconUsers,
    IconUsersPlus,
    IconFileInvoice,
    IconBuildingWarehouse,
    IconRulerMeasure,
    IconCurrencyDollar,
    IconWallet,
    IconFileSearch,
    IconTruckDelivery,
    IconTruckReturn,
    IconSpeakerphone,
    IconArrowsLeftRight,
    IconAlertCircle,
    IconListDetails,
    IconBrandWhatsapp,
    IconCashBanknote,
} from "@tabler/icons-react";
import hasAnyPermission from "./Permission";
import React from "react";

/**
 * Sidebar navigation.
 *
 * Every item is declared once in the flat `items` registry. The section a group
 * of items lands in — and the order of those groups — is then described by a
 * per-role layout, so the sidebar structure follows the active business
 * membership role (`auth.businessRole`).
 *
 * Layouts only decide *grouping and order*; `permissions` still decide whether
 * an item is rendered at all, and the server keeps enforcing capabilities. Any
 * visible item missing from the active layout is parked in "Other" so a layout
 * omission can never silently hide navigation.
 */
export default function Menu() {
    const { t } = useTranslation();
    const { url, props } = usePage();
    const isPlatformAdmin = props.auth?.super === true;
    const multiBusinessEnabled = props.multiBusinessEnabled === true;
    const businessRole = props.auth?.businessRole ?? null;

    const at = (path) => url === path;
    const under = (path) => url.startsWith(path);
    const glyph = (Icon) => <Icon size={20} strokeWidth={1.5} />;

    // ── Item registry ───────────────────────────────────────────────────
    const items = {
        dashboard: {
            title: t("sidebar.items.dashboard"),
            href: route("dashboard"),
            active: at("/dashboard"),
            icon: glyph(IconLayout2),
            permissions: hasAnyPermission(["dashboard-access"]),
        },
        categories: {
            title: t("sidebar.items.categories"),
            href: route("categories.index"),
            active: at("/dashboard/categories"),
            icon: glyph(IconFolder),
            permissions: hasAnyPermission(["categories-access"]),
        },
        products: {
            title: t("sidebar.items.products"),
            href: route("products.index"),
            active: at("/dashboard/products"),
            icon: glyph(IconBox),
            permissions: hasAnyPermission(["products-access"]),
        },
        customers: {
            title: t("sidebar.items.customers"),
            href: route("customers.index"),
            active: at("/dashboard/customers"),
            icon: glyph(IconUsersPlus),
            permissions: hasAnyPermission(["customers-access"]),
        },
        suppliers: {
            title: t("sidebar.items.suppliers"),
            href: route("suppliers.index"),
            active: under("/dashboard/suppliers"),
            icon: glyph(IconBuildingWarehouse),
            permissions: hasAnyPermission(["suppliers-access"]),
        },
        transactions: {
            title: t("sidebar.items.transactions"),
            href: route("transactions.index"),
            active: at("/dashboard/transactions"),
            icon: glyph(IconShoppingCart),
            permissions: hasAnyPermission(["transactions-access"]),
        },
        transactionHistory: {
            title: t("sidebar.items.transactionHistory"),
            href: route("transactions.history"),
            active: at("/dashboard/transactions/history"),
            icon: glyph(IconClockHour6),
            permissions: hasAnyPermission(["transactions-access"]),
        },
        salesReturns: {
            title: t("sidebar.items.salesReturns"),
            href: route("sales-returns.index"),
            active: under("/dashboard/sales-returns"),
            icon: glyph(IconFileCertificate),
            permissions: hasAnyPermission(["sales-returns-access"]),
        },
        receivables: {
            title: t("sidebar.items.receivables"),
            href: route("receivables.index"),
            active: under("/dashboard/receivables"),
            icon: glyph(IconFileInvoice),
            permissions: hasAnyPermission(["receivables-access"]),
        },
        aging: {
            title: t("sidebar.items.agingReminders"),
            href: route("aging.index"),
            active: under("/dashboard/aging"),
            icon: glyph(IconChartBar),
            permissions: hasAnyPermission(["receivables-access"]),
        },
        discountApproval: {
            title: t("sidebar.items.discountApproval"),
            href: route("discount-approvals.pending"),
            active: under("/dashboard/discount-approvals"),
            icon: glyph(IconAlertCircle),
            permissions: hasAnyPermission(["discounts-approve"]),
        },
        stockOpname: {
            title: t("sidebar.items.stockOpname"),
            href: route("stock-opnames.index"),
            active: under("/dashboard/stock-opnames"),
            icon: glyph(IconFileDescription),
            permissions: hasAnyPermission(["stock-opnames-access"]),
        },
        stockMutations: {
            title: t("sidebar.items.stockMutations"),
            href: route("stock-mutations.index"),
            active: under("/dashboard/stock-mutations"),
            icon: glyph(IconChartArrowsVertical),
            permissions: hasAnyPermission(["stock-mutations-access"]),
        },
        stockTransfers: {
            title: t("sidebar.items.stockTransfers"),
            href: route("stock-transfers.index"),
            active: under("/dashboard/stock-transfers"),
            icon: glyph(IconArrowsLeftRight),
            permissions: hasAnyPermission(["stock-transfers-access"]),
        },
        purchaseOrders: {
            title: t("sidebar.items.purchaseOrders"),
            href: route("purchase-orders.index"),
            active: under("/dashboard/purchase-orders"),
            icon: glyph(IconClipboardCheck),
            permissions: hasAnyPermission(["purchase-orders-access"]),
        },
        goodsReceivings: {
            title: t("sidebar.items.goodsReceiving"),
            href: route("goods-receivings.index"),
            active: under("/dashboard/goods-receivings"),
            icon: glyph(IconTruckDelivery),
            permissions: hasAnyPermission(["goods-receivings-access"]),
        },
        supplierReturns: {
            title: t("sidebar.items.supplierReturns"),
            href: route("supplier-returns.index"),
            active: under("/dashboard/supplier-returns"),
            icon: glyph(IconTruckReturn),
            permissions: hasAnyPermission(["supplier-returns-access"]),
        },
        payables: {
            title: t("sidebar.items.supplierPayables"),
            href: route("payables.index"),
            active: under("/dashboard/payables"),
            icon: glyph(IconCurrencyDollar),
            permissions: hasAnyPermission(["payables-access"]),
        },
        members: {
            title: t("sidebar.items.members"),
            href: route("members.index"),
            active: under("/dashboard/members"),
            icon: glyph(IconCrown),
            permissions: hasAnyPermission(["customers-access"]),
        },
        pricePromos: {
            title: t("sidebar.items.pricePromos"),
            href: route("pricing-rules.index"),
            active: under("/dashboard/pricing-rules"),
            icon: glyph(IconChartInfographic),
            permissions: hasAnyPermission(["pricing-rules-access"]),
        },
        customerVouchers: {
            title: t("sidebar.items.customerVouchers"),
            href: route("customer-vouchers.index"),
            active: under("/dashboard/customer-vouchers"),
            icon: glyph(IconCreditCard),
            permissions: hasAnyPermission(["customer-vouchers-access"]),
        },
        customerSegments: {
            title: t("sidebar.items.customerSegments"),
            href: route("customer-segments.index"),
            active: under("/dashboard/customer-segments"),
            icon: glyph(IconUsers),
            // Segment definitions have no outlet ownership, so the server fails
            // the whole surface closed (404) while tenancy is enabled. Hide the
            // entry point to avoid a dead link; the guard stays in place.
            permissions:
                !multiBusinessEnabled && hasAnyPermission(["customer-segments-access"]),
        },
        crmCampaigns: {
            title: t("sidebar.items.crmCampaigns"),
            href: route("crm-campaigns.index"),
            active: under("/dashboard/crm-campaigns"),
            icon: glyph(IconSpeakerphone),
            permissions: hasAnyPermission(["crm-campaigns-access"]),
        },
        crmReminders: {
            title: t("sidebar.items.crmReminders"),
            href: route("crm-reminders.index"),
            active: under("/dashboard/crm-reminders"),
            icon: glyph(IconClockHour6),
            permissions: hasAnyPermission(["crm-reminders-access"]),
        },
        salesReport: {
            title: t("sidebar.items.salesReport"),
            href: route("reports.sales.index"),
            active: under("/dashboard/reports/sales"),
            icon: glyph(IconChartArrowsVertical),
            permissions: hasAnyPermission(["reports-access"]),
        },
        profitReport: {
            title: t("sidebar.items.profitReport"),
            href: route("reports.profits.index"),
            active: under("/dashboard/reports/profits"),
            icon: glyph(IconChartBarPopular),
            permissions: hasAnyPermission(["profits-access"]),
        },
        advancedInsights: {
            title: t("sidebar.items.advancedInsights"),
            href: route("reports.insights.index"),
            active: under("/dashboard/reports/insights"),
            icon: glyph(IconChartBar),
            permissions: hasAnyPermission(["reports-access"]),
        },
        expenses: {
            title: t("sidebar.items.expenses"),
            href: route("expenses.index"),
            active: under("/dashboard/expenses"),
            icon: glyph(IconCashBanknote),
            permissions: hasAnyPermission(["expenses-access"]),
        },
        expenseCategories: {
            title: t("sidebar.items.expenseCategories"),
            href: route("expense-categories.index"),
            active: under("/dashboard/expense-categories"),
            icon: glyph(IconFolder),
            permissions: hasAnyPermission(["expense-categories-access"]),
        },
        expenseReport: {
            title: t("sidebar.items.expenseReport"),
            href: route("reports.expenses.index"),
            active: under("/dashboard/reports/expenses"),
            icon: glyph(IconCashBanknote),
            permissions: hasAnyPermission(["expenses-access"]),
        },
        profitLossReport: {
            title: t("sidebar.items.profitLossReport"),
            href: route("reports.profit-loss.index"),
            active: under("/dashboard/reports/laba-rugi"),
            icon: glyph(IconChartBar),
            permissions: hasAnyPermission(["reports-access"]),
        },
        cashierShifts: {
            title: t("sidebar.items.cashierShifts"),
            href: route("cashier-shifts.index"),
            active: under("/dashboard/cashier-shifts"),
            icon: glyph(IconWallet),
            permissions: hasAnyPermission(["cashier-shifts-access"]),
        },
        auditLogs: {
            title: t("sidebar.items.auditLogs"),
            href: route("audit-logs.index"),
            active: under("/dashboard/audit-logs"),
            icon: glyph(IconFileSearch),
            permissions: hasAnyPermission(["audit-logs-access"]),
        },
        permissions: {
            title: t("sidebar.items.permissions"),
            href: route("permissions.index"),
            active: at("/dashboard/permissions"),
            icon: glyph(IconUserBolt),
            permissions: hasAnyPermission(["permissions-access"]),
        },
        roles: {
            title: t("sidebar.items.roles"),
            href: route("roles.index"),
            active: at("/dashboard/roles"),
            icon: glyph(IconUserShield),
            permissions: hasAnyPermission(["roles-access"]),
        },
        businesses: {
            title: t("sidebar.items.businesses"),
            href: route("businesses.index"),
            active: at("/dashboard/businesses"),
            icon: glyph(IconBuildingStore),
            // Q6-A: platform administration — visible only when tenancy is
            // enabled AND the user is the platform super-admin.
            permissions: multiBusinessEnabled && isPlatformAdmin ? true : false,
        },
        users: {
            title: t("sidebar.items.users"),
            icon: glyph(IconUsers),
            permissions: hasAnyPermission(["users-access"]),
            subdetails: [
                {
                    title: t("sidebar.items.usersList"),
                    href: route("users.index"),
                    icon: glyph(IconTable),
                    active: at("/dashboard/users"),
                    permissions: hasAnyPermission(["users-access"]),
                },
                {
                    title: t("sidebar.items.usersCreate"),
                    href: route("users.create"),
                    icon: glyph(IconCirclePlus),
                    active: at("/dashboard/users/create"),
                    permissions: hasAnyPermission(["users-create"]),
                },
            ],
        },
        paymentGateway: {
            title: t("sidebar.items.paymentGateway"),
            href: route("settings.payments.edit"),
            active: at("/dashboard/settings/payments"),
            icon: glyph(IconCreditCard),
            permissions: hasAnyPermission(["payment-settings-access"]),
        },
        storeProfile: {
            title: t("sidebar.items.storeProfile"),
            href: route("settings.store"),
            active: at("/dashboard/settings/store"),
            icon: glyph(IconBuildingStore),
            permissions: hasAnyPermission(["dashboard-access"]),
        },
        bankAccounts: {
            title: t("sidebar.items.bankAccounts"),
            href: route("settings.bank-accounts.index"),
            active: at("/dashboard/settings/bank-accounts"),
            icon: glyph(IconCreditCard),
            permissions: hasAnyPermission(["payment-settings-access"]),
        },
        loyalty: {
            title: t("sidebar.items.loyalty"),
            href: route("settings.loyalty"),
            active: at("/dashboard/settings/loyalty"),
            icon: glyph(IconGift),
            permissions: hasAnyPermission(["dashboard-access"]),
        },
        salesTarget: {
            title: t("sidebar.items.salesTarget"),
            href: route("settings.target"),
            active: at("/dashboard/settings/target"),
            icon: glyph(IconChartInfographic),
            permissions: hasAnyPermission(["dashboard-access"]),
        },
        priceLists: {
            title: t("sidebar.items.priceLists"),
            href: route("price-lists.index"),
            active: under("/dashboard/settings/price-lists"),
            icon: glyph(IconListDetails),
            permissions: hasAnyPermission(["price-lists-access"]),
        },
        warehouses: {
            title: t("sidebar.items.warehouses"),
            href: route("settings.warehouses.index"),
            active: at("/dashboard/settings/warehouses"),
            icon: glyph(IconBuildingWarehouse),
            permissions: hasAnyPermission(["warehouses-access"]),
        },
        outlets: {
            title: t("sidebar.items.outlets"),
            href: route("settings.outlets.index"),
            active: at("/dashboard/settings/outlets"),
            icon: glyph(IconBuildingStore),
            permissions: hasAnyPermission(["outlets-access"]),
        },
        units: {
            title: t("sidebar.items.units"),
            href: route("settings.units.index"),
            active: at("/dashboard/settings/units"),
            icon: glyph(IconRulerMeasure),
            permissions: hasAnyPermission(["units-access"]),
        },
        whatsApp: {
            title: t("sidebar.items.whatsApp"),
            href: route("settings.whatsapp"),
            active: at("/dashboard/settings/whatsapp"),
            icon: glyph(IconBrandWhatsapp),
            permissions: hasAnyPermission(["whatsapp-settings-access"]),
        },
        apiTokens: {
            title: t("sidebar.items.apiTokens"),
            href: route("settings.api-tokens.index"),
            active: at("/dashboard/settings/api-tokens"),
            icon: glyph(IconKey),
            // Token milik sendiri: semua user yang login boleh mengelolanya,
            // dan abilities yang bisa dipilih dibatasi ke permission si pembuat.
            permissions: true,
        },
    };

    // ── Quick actions (rendered as buttons above the sections) ───────────
    // These items are pulled out of the section lists below and rendered as a
    // button block at the top of the sidebar, tinted with the role accent.
    // An unresolved role gets no buttons at all (fail-quiet, matching the
    // canonical fallback used for structure).
    const actionLayouts = {
        "super-admin": [{ id: "dashboard", variant: "solid" }],
        owner: [{ id: "dashboard", variant: "solid" }],
        manager: [{ id: "dashboard", variant: "solid" }],
        staff: [
            { id: "transactions", variant: "solid" },
            { id: "cashierShifts", variant: "tinted" },
        ],
    };

    // ── Canonical grouping (also the fallback for an unresolved role) ────
    // `dashboard` is intentionally absent: every canonical role renders it as a
    // quick action button instead (see `actionLayouts`).
    const canonicalLayout = [
        { section: "masterData", items: ["categories", "products", "customers", "suppliers", "expenseCategories"] },
        { section: "sales", items: ["transactions", "transactionHistory", "salesReturns", "receivables", "aging"] },
        { section: "approval", items: ["discountApproval"] },
        { section: "inventory", items: ["stockOpname", "stockMutations", "stockTransfers"] },
        { section: "procurement", items: ["purchaseOrders", "goodsReceivings", "supplierReturns", "payables"] },
        { section: "crmPricing", items: ["members", "pricePromos", "customerVouchers", "customerSegments", "crmCampaigns", "crmReminders"] },
        { section: "reports", items: ["salesReport", "profitReport", "advancedInsights", "expenseReport", "profitLossReport"] },
        { section: "operations", items: ["cashierShifts", "expenses", "auditLogs"] },
        { section: "userManagement", items: ["permissions", "roles", "businesses", "users"] },
        { section: "settings", items: ["paymentGateway", "storeProfile", "bankAccounts", "loyalty", "salesTarget", "priceLists", "warehouses", "outlets", "units", "whatsApp", "apiTokens"] },
    ];

    // ── Per-role structure ───────────────────────────────────────────────
    // Owner / manager / platform admin keep the operational grouping; the POS
    // role gets a dedicated layout led by the cashier's day-to-day tools.
    const layouts = {
        "super-admin": canonicalLayout,
        owner: canonicalLayout,
        manager: canonicalLayout,
        staff: [
            { section: "kasir", items: ["dashboard", "transactionHistory", "expenses"] },
            { section: "sales", items: ["receivables", "aging"] },
            { section: "procurement", items: ["suppliers", "payables"] },
            { section: "customers", items: ["customers", "members"] },
            { section: "settings", items: ["storeProfile", "loyalty", "salesTarget", "apiTokens"] },
        ],
    };

    const sectionTitles = {
        overview: t("sidebar.sections.overview"),
        masterData: t("sidebar.sections.masterData"),
        sales: t("sidebar.sections.sales"),
        approval: t("sidebar.sections.approval"),
        inventory: t("sidebar.sections.inventory"),
        procurement: t("sidebar.sections.procurement"),
        crmPricing: t("sidebar.sections.crmPricing"),
        reports: t("sidebar.sections.reports"),
        operations: t("sidebar.sections.operations"),
        userManagement: t("sidebar.sections.userManagement"),
        settings: t("sidebar.sections.settings"),
        kasir: t("sidebar.sections.kasir"),
        customers: t("sidebar.sections.customers"),
        other: t("sidebar.sections.other"),
    };

    const layout = layouts[businessRole] ?? canonicalLayout;
    const placed = new Set();

    const menuNavigation = layout
        .map(({ section, items: ids }) => {
            const details = ids
                .map((id) => {
                    placed.add(id);

                    return items[id];
                })
                .filter(Boolean);

            return { title: sectionTitles[section] ?? section, details };
        })
        .filter((group) => group.details.length > 0);

    // Quick action buttons, prepended above the sections. Items already placed
    // here are marked as placed so the safety net below never duplicates them.
    const actionBlock = (actionLayouts[businessRole] ?? [])
        .map(({ id, variant }) => {
            const item = items[id];

            if (!item || item.permissions !== true) return null;

            placed.add(id);

            return { ...item, variant };
        })
        .filter(Boolean);

    if (actionBlock.length > 0) {
        menuNavigation.unshift({
            title: null,
            isActionBlock: true,
            details: actionBlock,
        });
    }

    // Safety net: a visible item missing from this role's layout is grouped
    // under "Other" rather than silently dropped.
    const unplaced = Object.entries(items)
        .filter(([id, item]) => !placed.has(id) && item.permissions === true)
        .map(([, item]) => item);

    if (unplaced.length > 0) {
        menuNavigation.push({ title: sectionTitles.other, details: unplaced });
    }

    return menuNavigation;
}
