import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, usePage, Link } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import {
    IconCirclePlus,
    IconDatabaseOff,
    IconPencilCog,
    IconTrash,
    IconBarcode,
    IconPrinter,
    IconSearch,
    IconArrowLeft,
    IconUpload,
    IconDownload,
} from "@tabler/icons-react";
import Search from "@/Components/Dashboard/Search";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import ViewToggle from "@/Components/Dashboard/ViewToggle";
import BarcodePrintModal from "@/Components/Barcode/BarcodePrintModal";
import { useAuthorization } from "@/Utils/authorization";
import { router } from "@inertiajs/react";

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

// Product Card for Grid View (ringkas — tanpa slot gambar)
function ProductCard({ product, isSelected, onToggle, canUpdate, canDelete }) {
    const lowStock = product.stock > 0 && product.stock <= 5;
    const outOfStock = product.stock === 0;
    const isComposite = product.is_composite;
    const profit =
        Number(product.sell_price || 0) - Number(product.buy_price || 0);
    const details = [
        product.sku ? `SKU: ${product.sku}` : null,
        product.barcode ? `Barcode: ${product.barcode}` : null,
    ]
        .filter(Boolean)
        .join(" · ");

    return (
        <div
            title={details ? `${product.title} (${details})` : product.title}
            className={`flex flex-col rounded-xl border bg-white p-2.5 transition-all duration-200 dark:bg-slate-900 ${
                isSelected
                    ? "border-primary-500 ring-2 ring-primary-500/20"
                    : "border-slate-200 hover:border-slate-300 hover:shadow-md dark:border-slate-800 dark:hover:border-slate-700"
            }`}
        >
            {/* Baris 1: pilih + nama */}
            <div className="flex items-start gap-1.5">
                <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => onToggle(product)}
                    aria-label={`Pilih ${product.title}`}
                    className="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-slate-300 text-primary-500 focus:ring-primary-500"
                />
                <h3 className="min-w-0 flex-1 text-sm font-semibold leading-snug text-slate-800 line-clamp-2 dark:text-slate-200">
                    {product.title}
                </h3>
            </div>

            {/* Baris 2: kategori + komposit + stok + aksi */}
            <div className="mt-1.5 flex flex-wrap items-center gap-1">
                <span className="max-w-full truncate rounded bg-primary-100 px-1.5 py-0.5 text-[11px] font-medium text-primary-700 dark:bg-primary-900/50 dark:text-primary-400">
                    {product.category?.name || "Kategori"}
                </span>
                {isComposite && (
                    <span className="rounded-full bg-primary-500 px-2 py-0.5 text-[11px] font-semibold text-white">
                        Komposit
                    </span>
                )}
                <span
                    className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                        outOfStock
                            ? "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-400"
                            : lowStock
                              ? "bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-400"
                              : "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400"
                    }`}
                >
                    {outOfStock ? "Habis" : `Stok ${product.stock}`}
                </span>
                {(canUpdate || canDelete) && (
                    <div className="ml-auto flex shrink-0 items-center gap-0.5">
                        {canUpdate && (
                            <Link
                                href={route("products.edit", product.id)}
                                aria-label={`Ubah ${product.title}`}
                                title={`Ubah ${product.title}`}
                                className="p-1 rounded-xl text-slate-400 transition-colors hover:bg-warning-50 hover:text-warning-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 dark:hover:bg-warning-900/30"
                            >
                                <IconPencilCog size={15} />
                            </Link>
                        )}
                        {canDelete && (
                            /* Penanda: !p-1 menimpa padding bawaan Button agar ikon kecil */
                            <Button
                                type={"delete"}
                                icon={<IconTrash size={15} />}
                                aria-label={`Hapus ${product.title}`}
                                title={`Hapus ${product.title}`}
                                className={
                                    "!p-1 text-slate-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/30"
                                }
                                url={route("products.destroy", product.id)}
                            />
                        )}
                    </div>
                )}
            </div>

            {/* Baris 3: harga jual + modal */}
            <div className="mt-auto pt-2">
                <p className="truncate text-base font-bold text-primary-600 dark:text-primary-400">
                    {formatCurrency(product.sell_price)}
                </p>
                <p className="truncate text-[11px] text-slate-400 dark:text-slate-500">
                    Modal {formatCurrency(product.buy_price)}
                    {profit > 0 && (
                        <span className="font-medium text-success-600 dark:text-success-400">
                            {" · +"}
                            {formatCurrency(profit)}
                        </span>
                    )}
                </p>
            </div>
        </div>
    );
}

export default function Index({ products }) {
    const { can } = useAuthorization();
    const { url } = usePage();
    const activeSearch =
        new URLSearchParams(url.split("?")[1] || "").get("search") || "";
    const isSearching = activeSearch.trim() !== "";
    const [viewMode, setViewMode] = useState("grid"); // 'grid' | 'list'
    const [showBarcodeModal, setShowBarcodeModal] = useState(false);
    const [singleProductBarcode, setSingleProductBarcode] = useState(null);
    const [selectedProducts, setSelectedProducts] = useState([]);
    const canCreateProducts = can("products-create");
    const canEditProducts = can("products-edit");
    const canDeleteProducts = can("products-delete");

    const handlePrintSingleBarcode = (product) => {
        setSingleProductBarcode(product);
        setSelectedProducts([]);
        setShowBarcodeModal(true);
    };

    const handlePrintAllBarcodes = () => {
        setSingleProductBarcode(null);
        setSelectedProducts(products.data);
        setShowBarcodeModal(true);
    };

    const handlePrintSelected = () => {
        if (selectedProducts.length === 0) return;
        setSingleProductBarcode(null);
        setShowBarcodeModal(true);
    };

    const toggleProductSelection = (product) => {
        setSelectedProducts((prev) => {
            const isSelected = prev.some((p) => p.id === product.id);
            if (isSelected) {
                return prev.filter((p) => p.id !== product.id);
            } else {
                return [...prev, product];
            }
        });
    };

    const toggleSelectAll = () => {
        if (selectedProducts.length === products.data.length) {
            setSelectedProducts([]);
        } else {
            setSelectedProducts([...products.data]);
        }
    };

    const handlePerPageChange = (value) => {
        // reset pilihan agar tidak menyimpan produk dari halaman sebelumnya
        setSelectedProducts([]);
        router.get(
            route("products.index"),
            {
                ...(activeSearch ? { search: activeSearch } : {}),
                per_page: value,
            },
            { preserveState: true, preserveScroll: true }
        );
    };

    const isProductSelected = (productId) =>
        selectedProducts.some((p) => p.id === productId);

    return (
        <>
            <Head title="Produk" />

            {/* Header */}
            <div data-tour="products-header" className="mb-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">
                            Produk
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            {products.total} produk terdaftar
                        </p>
                    </div>
                    <div
                        data-tour="products-actions"
                        className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto"
                    >
                        <button
                            onClick={handlePrintAllBarcodes}
                            aria-label="Cetak semua barcode produk"
                            className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors w-full sm:w-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                        >
                            <IconBarcode size={18} />
                            Cetak All Barcode
                        </button>
                        {canCreateProducts && (
                            <>
                                <a
                                    href={route("export.products")}
                                    className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors w-full sm:w-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                                >
                                    <IconDownload size={18} />
                                    Export
                                </a>
                                <button
                                    type="button"
                                    onClick={() => document.getElementById("import-products-input")?.click()}
                                    className="inline-flex items-center justify-center gap-2 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 text-sm font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors w-full sm:w-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                                >
                                    <IconUpload size={18} />
                                    Import
                                </button>
                                <input
                                    id="import-products-input"
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    className="hidden"
                                    onChange={function(e) {
                                        const file = e.target.files && e.target.files[0];
                                        if (file) router.post(route("import.products"), { file });
                                    }}
                                />
                            </>
                        )}
                        {canCreateProducts && (
                            <Button
                                type={"link"}
                                icon={
                                    <IconCirclePlus
                                        size={18}
                                        strokeWidth={1.5}
                                    />
                                }
                                className={
                                    "bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30 w-full sm:w-auto justify-center"
                                }
                                label={"Tambah Produk"}
                                href={route("products.create")}
                            />
                        )}
                    </div>
                </div>
            </div>

            {/* Toolbar */}
            <div
                data-tour="products-search"
                className="mb-4 flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-3"
            >
                <div className="flex items-center gap-3">
                    <div className="w-full sm:w-80">
                        <Search
                            url={route("products.index")}
                            placeholder="Cari produk..."
                        />
                    </div>
                    {/* Select All Checkbox */}
                    <label className="flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={
                                selectedProducts.length ===
                                    products.data.length &&
                                products.data.length > 0
                            }
                            onChange={toggleSelectAll}
                            aria-label="Pilih semua produk di halaman ini"
                            className="w-4 h-4 rounded border-slate-300 text-primary-500 focus:ring-primary-500"
                        />
                        <span className="text-sm text-slate-600 dark:text-slate-400 hidden sm:inline">
                            Pilih Semua
                        </span>
                    </label>
                    {/* Jumlah produk per halaman */}
                    <label className="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                        <span className="hidden sm:inline">Per halaman</span>
                        <select
                            value={products.per_page}
                            onChange={(e) => handlePerPageChange(e.target.value)}
                            aria-label="Jumlah produk per halaman"
                            className="h-10 rounded-xl border border-slate-200 bg-white px-2 text-sm text-slate-700 transition-colors focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                        >
                            {[10, 25, 50, 100].map((option) => (
                                <option key={option} value={option}>
                                    {option}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div data-tour="products-view" className="flex items-center gap-2">
                    {/* Show selection count and print selected button */}
                    {selectedProducts.length > 0 && (
                        <button
                            onClick={handlePrintSelected}
                            className="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                        >
                            <IconPrinter size={18} />
                            Cetak Terpilih ({selectedProducts.length})
                        </button>
                    )}
                    <ViewToggle value={viewMode} onChange={setViewMode} />
                </div>
            </div>

            {/* Content */}
            <div data-tour="products-list" className="contents">
            {products.data.length > 0 ? (
                viewMode === "grid" ? (
                    /* Grid View */
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 2xl:grid-cols-8">
                        {products.data.map((product) => (
                            <ProductCard
                                key={product.id}
                                product={product}
                                isSelected={isProductSelected(product.id)}
                                onToggle={toggleProductSelection}
                                canUpdate={canEditProducts}
                                canDelete={canDeleteProducts}
                            />
                        ))}
                    </div>
                ) : (
                    /* List View */
                    <Table.Card title={"Data Produk"}>
                        <Table>
                            <Table.Thead>
                                <tr>
                                    <Table.Th className="w-10">No</Table.Th>
                                    <Table.Th>Produk</Table.Th>
                                    <Table.Th>Kategori</Table.Th>
                                    <Table.Th>Harga Beli</Table.Th>
                                    <Table.Th>Harga Jual</Table.Th>
                                    <Table.Th>Stok</Table.Th>
                                    <Table.Th></Table.Th>
                                </tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {products.data.map((product, i) => (
                                    <tr
                                        className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                                        key={product.id}
                                    >
                                        <Table.Td className="text-center">
                                            {++i +
                                                (products.current_page - 1) *
                                                    products.per_page}
                                        </Table.Td>
                                        <Table.Td>
                                            <p className="text-sm font-medium text-slate-800 dark:text-slate-200">
                                                {product.title}
                                            </p>
                                            <div className="text-xs text-slate-500 dark:text-slate-400 space-y-0.5">
                                                {product.barcode && (
                                                    <p>
                                                        Barcode: {product.barcode}
                                                    </p>
                                                )}
                                                {product.sku && <p>SKU: {product.sku}</p>}
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <span className="px-2 py-0.5 text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded">
                                                {product.category?.name}
                                            </span>
                                        </Table.Td>
                                        <Table.Td>
                                            {formatCurrency(product.buy_price)}
                                        </Table.Td>
                                        <Table.Td className="font-semibold text-primary-600 dark:text-primary-400">
                                            {formatCurrency(product.sell_price)}
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                className={`px-2 py-0.5 text-xs font-medium rounded ${
                                                    product.stock === 0
                                                        ? "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-400"
                                                        : product.stock <= 5
                                                        ? "bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-400"
                                                        : "bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-400"
                                                }`}
                                            >
                                                {product.stock}
                                            </span>
                                        </Table.Td>
                                        <Table.Td>
                                            <div className="flex gap-2">
                                                {canEditProducts && (
                                                    <Button
                                                        type={"edit"}
                                                        icon={
                                                            <IconPencilCog
                                                                size={16}
                                                                strokeWidth={
                                                                    1.5
                                                                }
                                                            />
                                                        }
                                                        className={
                                                            "border bg-warning-100 border-warning-200 text-warning-600 hover:bg-warning-200 dark:bg-warning-900/50 dark:border-warning-800 dark:text-warning-400"
                                                        }
                                                        aria-label={`Ubah ${product.title}`}
                                                        href={route(
                                                            "products.edit",
                                                            product.id
                                                        )}
                                                    />
                                                )}
                                                {canDeleteProducts && (
                                                    <Button
                                                        type={"delete"}
                                                        icon={
                                                            <IconTrash
                                                                size={16}
                                                                strokeWidth={
                                                                    1.5
                                                                }
                                                            />
                                                        }
                                                        className={
                                                            "border bg-danger-100 border-danger-200 text-danger-600 hover:bg-danger-200 dark:bg-danger-900/50 dark:border-danger-800 dark:text-danger-400"
                                                        }
                                                        aria-label={`Hapus ${product.title}`}
                                                        url={route(
                                                            "products.destroy",
                                                            product.id
                                                        )}
                                                    />
                                                )}
                                            </div>
                                        </Table.Td>
                                    </tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.Card>
                )
            ) : (
                /* Empty State */
                <div className="flex flex-col items-center justify-center py-16 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                        {isSearching ? (
                            <IconSearch
                                size={32}
                                className="text-slate-400"
                                strokeWidth={1.5}
                            />
                        ) : (
                            <IconDatabaseOff
                                size={32}
                                className="text-slate-400"
                                strokeWidth={1.5}
                            />
                        )}
                    </div>
                    <h3 className="text-lg font-medium text-slate-800 dark:text-slate-200 mb-1">
                        {isSearching ? "Produk Tidak Ditemukan" : "Belum Ada Produk"}
                    </h3>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        {isSearching
                            ? `Tidak ada produk yang cocok dengan "${activeSearch}".`
                            : "Tambahkan produk pertama Anda untuk memulai."}
                    </p>
                    {isSearching ? (
                        <Button
                            type={"link"}
                            icon={<IconArrowLeft size={18} />}
                            className={
                                "border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300"
                            }
                            label={"Reset Pencarian"}
                            href={route("products.index")}
                        />
                    ) : (
                        canCreateProducts && (
                            <Button
                                type={"link"}
                                icon={<IconCirclePlus size={18} />}
                                className={
                                    "bg-primary-500 hover:bg-primary-600 text-white"
                                }
                                label={"Tambah Produk"}
                                href={route("products.create")}
                            />
                        )
                    )}
                </div>
            )}
            </div>

            {products.last_page !== 1 && <Pagination links={products.links} />}

            {/* Barcode Print Modal */}
            <BarcodePrintModal
                isOpen={showBarcodeModal}
                onClose={() => {
                    setShowBarcodeModal(false);
                    setSingleProductBarcode(null);
                }}
                products={selectedProducts}
                singleProduct={singleProductBarcode}
            />
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;
