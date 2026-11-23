import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Image Docker légère : le serveur et ses seules dépendances utiles (voir infrastructure/docker/web.Dockerfile).
  output: "standalone",
  poweredByHeader: false,
};

export default nextConfig;
