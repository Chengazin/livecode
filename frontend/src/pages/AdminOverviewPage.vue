<template>
  <section class="page admin-overview-page">
    <AdminUsersSection />
    <AdminProjectsSection />
    <AdminParticipantsSection />
    <AdminInvitationsSection />
    <AdminTerminalSessionsSection />
  </section>
</template>

<script setup>
import AdminUsersSection from "../components/admin/AdminUsersSection.vue";
import AdminProjectsSection from "../components/admin/AdminProjectsSection.vue";
import AdminParticipantsSection from "../components/admin/AdminParticipantsSection.vue";
import AdminInvitationsSection from "../components/admin/AdminInvitationsSection.vue";
import AdminTerminalSessionsSection from "../components/admin/AdminTerminalSessionsSection.vue";
</script>
