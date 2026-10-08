import React from "react";
import SalesReturnForm from "./Form";

export default function Create({ transaction }) {
    return (
        <SalesReturnForm
            title="Buat Retur Penjualan"
            transaction={transaction}
            submitRoute={route("sales-returns.store", transaction.id)}
            submitMethod="post"
            canEdit
        />
    );
}

Create.layout = SalesReturnForm.layout;
