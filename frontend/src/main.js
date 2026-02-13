import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import i18n from "./i18n";
import { initializePreferences } from "./services/preferences";
import "./styles.css";

initializePreferences();

createApp(App).use(router).use(i18n).mount("#app");
