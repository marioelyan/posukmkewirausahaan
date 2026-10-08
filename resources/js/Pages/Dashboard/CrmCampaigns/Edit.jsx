import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Form from "./Form";

export default function Edit(props) {
    return <Form {...props} mode="edit" />;
}

Edit.layout = (page) => <DashboardLayout children={page} />;
