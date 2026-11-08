import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Zo Stream Admin",
    short_name: "Zo Admin",
    description: "Admin workspace for managing Zo Stream content and users.",
    start_url: "/dashboard",
    scope: "/",
    display: "standalone",
    background_color: "#f1f1f1",
    theme_color: "#f1f1f1",
    orientation: "portrait",
    categories: ["business", "entertainment", "productivity"],
    icons: [
      {
        src: "/logo/logo.png",
        sizes: "any",
        type: "image/png",
        purpose: "any",
      },
      {
        src: "/logo/logo.png",
        sizes: "1254x1254",
        type: "image/png",
        purpose: "maskable",
      },
    ],
    shortcuts: [
      {
        name: "Dashboard",
        short_name: "Dashboard",
        description: "Open the Zo Stream admin dashboard.",
        url: "/dashboard",
        icons: [{ src: "/logo/logo.png", sizes: "1254x1254" }],
      },
      {
        name: "Add Movie",
        short_name: "Add Movie",
        description: "Create a new movie entry.",
        url: "/movies/add",
        icons: [{ src: "/logo/logo.png", sizes: "1254x1254" }],
      },
    ],
  };
}
