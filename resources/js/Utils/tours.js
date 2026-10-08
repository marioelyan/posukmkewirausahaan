import i18n from "@/i18n";

const t = (key) => i18n.t(`tour.${key}`);

export const dashboardTour = {
  showProgress: true,
  steps: [
    {
      element: "[data-tour='dashboard-header']",
      popover: { title: t("dashboard.title"), description: t("dashboard.desc") },
    },
    {
      element: "[data-tour='dashboard-stats']",
      popover: { title: t("dashboard.stats.title"), description: t("dashboard.stats.desc") },
    },
    {
      element: "[data-tour='dashboard-chart']",
      popover: { title: t("dashboard.chart.title"), description: t("dashboard.chart.desc") },
    },
    {
      element: "[data-tour='dashboard-widgets']",
      popover: { title: t("dashboard.widgets.title"), description: t("dashboard.widgets.desc") },
    },
    {
      element: "[data-tour='dashboard-recent']",
      popover: { title: t("dashboard.recent.title"), description: t("dashboard.recent.desc") },
    },
    {
      element: "[data-tour='dashboard-lowstock']",
      popover: { title: t("dashboard.lowstock.title"), description: t("dashboard.lowstock.desc") },
    },
  ],
};

export const posTour = {
  showProgress: true,
  steps: [
    {
      element: "[data-tour='pos-search']",
      popover: { title: t("pos.search.title"), description: t("pos.search.desc") },
    },
    {
      element: "[data-tour='pos-products']",
      popover: { title: t("pos.products.title"), description: t("pos.products.desc") },
    },
    {
      element: "[data-tour='pos-customer']",
      popover: { title: t("pos.customer.title"), description: t("pos.customer.desc") },
    },
    {
      element: "[data-tour='pos-cart']",
      popover: { title: t("pos.cart.title"), description: t("pos.cart.desc") },
    },
    {
      element: "[data-tour='pos-payment']",
      popover: { title: t("pos.payment.title"), description: t("pos.payment.desc") },
    },
  ],
};

export const productsTour = {
  showProgress: true,
  steps: [
    {
      element: "[data-tour='products-header']",
      popover: { title: t("products.header.title"), description: t("products.header.desc") },
    },
    {
      element: "[data-tour='products-actions']",
      popover: { title: t("products.actions.title"), description: t("products.actions.desc") },
    },
    {
      element: "[data-tour='products-search']",
      popover: { title: t("products.search.title"), description: t("products.search.desc") },
    },
    {
      element: "[data-tour='products-view']",
      popover: { title: t("products.view.title"), description: t("products.view.desc") },
    },
    {
      element: "[data-tour='products-list']",
      popover: { title: t("products.list.title"), description: t("products.list.desc") },
    },
  ],
};

export const cashierShiftsTour = {
  showProgress: true,
  steps: [
    {
      element: "[data-tour='shifts-header']",
      popover: { title: t("shifts.header.title"), description: t("shifts.header.desc") },
    },
    {
      element: "[data-tour='shifts-open']",
      popover: { title: t("shifts.open.title"), description: t("shifts.open.desc") },
    },
    {
      element: "[data-tour='shifts-active']",
      popover: { title: t("shifts.active.title"), description: t("shifts.active.desc") },
    },
    {
      element: "[data-tour='shifts-filters']",
      popover: { title: t("shifts.filters.title"), description: t("shifts.filters.desc") },
    },
    {
      element: "[data-tour='shifts-history']",
      popover: { title: t("shifts.history.title"), description: t("shifts.history.desc") },
    },
  ],
};

export const reportsTour = {
  showProgress: true,
  steps: [
    {
      element: "[data-tour='reports-header']",
      popover: { title: t("reports.header.title"), description: t("reports.header.desc") },
    },
    {
      element: "[data-tour='reports-summary']",
      popover: { title: t("reports.summary.title"), description: t("reports.summary.desc") },
    },
    {
      element: "[data-tour='reports-filters']",
      popover: { title: t("reports.filters.title"), description: t("reports.filters.desc") },
    },
    {
      element: "[data-tour='reports-table']",
      popover: { title: t("reports.table.title"), description: t("reports.table.desc") },
    },
  ],
};

export const tourButtonTitle = t("button");
