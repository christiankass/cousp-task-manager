import { MeProvider } from "@/components/MeProvider";
import { Sidebar } from "@/components/Sidebar";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <MeProvider fallback={<div className="p-8 text-slate-500">…</div>}>
      <div className="flex min-h-screen flex-col md:flex-row">
        <Sidebar />
        <main className="flex-1 p-4 md:p-8">{children}</main>
      </div>
    </MeProvider>
  );
}
