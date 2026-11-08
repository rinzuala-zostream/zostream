"use client";

import { ToastContainer } from "react-toastify";

export function AppToaster() {
  return (
    <ToastContainer
      position="top-center"
      autoClose={2200}
      hideProgressBar
      closeOnClick
      newestOnTop
      pauseOnHover
      pauseOnFocusLoss={false}
      theme="colored"
      toastClassName="!rounded-xl"
    />
  );
}

