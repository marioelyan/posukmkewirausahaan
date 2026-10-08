import React from "react";
import SalesReturnForm from "./Form";
import { useAuthorization } from "@/Utils/authorization";

export default function Show({ salesReturn, transaction }) {
    const { can } = useAuthorization();

    return (
        <SalesReturnForm
            title={salesReturn.code}
            transaction={transaction}
            salesReturn={salesReturn}
            submitRoute={route("sales-returns.update", salesReturn.id)}
            submitMethod="patch"
            canEdit={
                salesReturn.status === "draft" &&
                can("sales-returns-create")
            }
            canComplete={
                salesReturn.status === "draft" &&
                can("sales-returns-complete")
            }
            completeRoute={route("sales-returns.complete", salesReturn.id)}
        />
    );
}

Show.layout = SalesReturnForm.layout;
