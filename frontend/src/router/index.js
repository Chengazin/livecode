import { createRouter, createWebHistory } from "vue-router";
import HomePage from "../pages/HomePage.vue";
import LoginPage from "../pages/LoginPage.vue";
import RegisterPage from "../pages/RegisterPage.vue";
import ApiExplorerPage from "../pages/ApiExplorerPage.vue";
import ForgejoCallbackPage from "../pages/ForgejoCallbackPage.vue";
import CodeEditorPage from "../pages/CodeEditorPage.vue";
import ProfilePage from "../pages/ProfilePage.vue";
import AdminPage from "../pages/AdminPage.vue";
import AdminOverviewPage from "../pages/AdminOverviewPage.vue";
import { clearSession, getSession, setUser } from "../services/auth";
import { request } from "../services/api";

const routes = [
  {
    path: "/",
    name: "home",
    component: HomePage,
  },
  {
    path: "/editor",
    name: "editor",
    component: CodeEditorPage,
  },
  {
    path: "/profile",
    name: "profile",
    component: ProfilePage,
  },
  {
    path: "/login",
    name: "login",
    component: LoginPage,
  },
  {
    path: "/register",
    name: "register",
    component: RegisterPage,
  },
  {
    path: "/admin",
    component: AdminPage,
    meta: { requiresAdmin: true },
    children: [
      {
        path: "",
        name: "admin",
        component: AdminOverviewPage,
      },
      {
        path: "explorer",
        name: "admin-explorer",
        component: ApiExplorerPage,
      },
    ],
  },
  {
    path: "/explorer",
    redirect: "/admin/explorer",
  },
  {
    path: "/forgejo/callback",
    name: "forgejo-callback",
    component: ForgejoCallbackPage,
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach(async (to) => {
  const requiresAdmin = to.matched.some((record) => record.meta?.requiresAdmin);
  if (!requiresAdmin) {
    return true;
  }

  const session = getSession();
  if (!session.accessToken) {
    return { path: "/login", query: { redirect: to.fullPath } };
  }

  let user = session.user;

  try {
    const response = await request({
      method: "GET",
      path: "/me",
      auth: true,
    });

    user = response.data || null;
    setUser(user);
  } catch (_error) {
    clearSession();
    return { path: "/login", query: { redirect: to.fullPath } };
  }

  if (!user?.is_admin) {
    return { path: "/editor" };
  }

  return true;
});

export default router;
