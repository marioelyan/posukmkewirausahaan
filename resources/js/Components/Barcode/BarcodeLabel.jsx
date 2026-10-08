import React, { useRef } from "react";
import JsBarcode from "jsbarcode";
import { useEffect } from "react";

/**
 * BarcodeLabel Component
 * Supports multiple sizes: 50x30mm (default), 70x50mm, 100x50mm
 */
export default function BarcodeLabel({
    product,
    size = "50x30", // '50x30' | '70x50' | '100x50'
    showPrice = true,
    showOngkir = false,
    ongkirAmount = 0,
}) {
    const barcodeRef = useRef(null);

    const sizes = {
        "50x30": {
            width: "50mm",
            height: "30mm",
            barcodeWidth: 1.5,
            barcodeHeight: 40,
        },
        "70x50": {
            width: "70mm",
            height: "50mm",
            barcodeWidth: 2,
            barcodeHeight: 50,
        },
        "100x50": {
            width: "100mm",
            height: "50mm",
            barcodeWidth: 2.5,
            barcodeHeight: 60,
        },
    };

    const currentSize = sizes[size] || sizes["50x30"];

    useEffect(() => {
        if (barcodeRef.current && product?.barcode) {
            try {
                JsBarcode(barcodeRef.current, product.barcode, {
                    format: "CODE128",
                    width: currentSize.barcodeWidth,
                    height: currentSize.barcodeHeight,
                    displayValue: true,
                    fontSize: 12,
                    margin: 5,
                    background: "#ffffff",
                });
            } catch (e) {
                console.error("Barcode generation error:", e);
            }
        }
    }, [product?.barcode, currentSize]);

    const formatPrice = (value = 0) =>
        value.toLocaleString("id-ID", {
            style: "currency",
            currency: "IDR",
            minimumFractionDigits: 0,
        });

    if (!product) return null;

    return (
        <div
            className="barcode-label bg-white border border-slate-300 p-2 flex flex-col items-center justify-center"
            style={{
                width: currentSize.width,
                height: currentSize.height,
                pageBreakInside: "avoid",
            }}
        >
            {/* Product Name */}
            <p
                className="text-center font-semibold text-slate-800 leading-tight mb-1 line-clamp-2"
                style={{ fontSize: size === "50x30" ? "10px" : "12px" }}
            >
                {product.title}
            </p>

            {/* Barcode */}
            <svg ref={barcodeRef} className="max-w-full" />

            {/* Price */}
            {showPrice && (
                <p
                    className="font-bold text-slate-900 mt-1"
                    style={{ fontSize: size === "50x30" ? "12px" : "14px" }}
                >
                    {formatPrice(product.sell_price)}
                </p>
            )}

            {/* Ongkir */}
            {showOngkir && ongkirAmount > 0 && (
                <p
                    className="text-slate-500 mt-0.5"
                    style={{ fontSize: size === "50x30" ? "8px" : "10px" }}
                >
                    + Ongkir {formatPrice(ongkirAmount)}
                </p>
            )}
        </div>
    );
}

/**
 * Generate multiple barcode labels for printing
 */
export function BarcodeLabelGrid({
    products = [],
    size = "50x30",
    showPrice = true,
    showOngkir = false,
    ongkirAmount = 0,
    copies = 1,
}) {
    // Generate array with copies
    const labelsToRender = products.flatMap((product) =>
        Array(copies).fill(product)
    );

    const gridCols = {
        "50x30": "repeat(4, 50mm)",
        "70x50": "repeat(3, 70mm)",
        "100x50": "repeat(2, 100mm)",
    };

    return (
        <div
            className="barcode-grid"
            style={{
                display: "grid",
                gridTemplateColumns: gridCols[size] || gridCols["50x30"],
                gap: "2mm",
            }}
        >
            {labelsToRender.map((product, index) => (
                <BarcodeLabel
                    key={`${product.id}-${index}`}
                    product={product}
                    size={size}
                    showPrice={showPrice}
                    showOngkir={showOngkir}
                    ongkirAmount={ongkirAmount}
                />
            ))}
        </div>
    );
}
