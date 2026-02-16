const { defineConfig } = require("@vue/cli-service");

const backendTarget = process.env.VUE_APP_BACKEND_URL || "http://127.0.0.1:8000";

module.exports = defineConfig({
  transpileDependencies: true,
  devServer: {
    proxy: {
      "^/api": {
        target: backendTarget,
        changeOrigin: true,
      },
      "^/storage": {
        target: backendTarget,
        changeOrigin: true,
      },
    },
  },
});
