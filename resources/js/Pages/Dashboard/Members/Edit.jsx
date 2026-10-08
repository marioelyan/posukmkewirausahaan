import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Form from "./Form";

export default function Edit({ member }) {
    return <Form mode="edit" member={member} />;
}

Edit.layout = (page) => <DashboardLayout children={page} />;
