import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Form from "./Form";

export default function Create() {
    return <Form mode="create" />;
}

Create.layout = (page) => <DashboardLayout children={page} />;
