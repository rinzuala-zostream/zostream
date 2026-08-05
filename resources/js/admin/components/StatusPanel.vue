<script setup>defineProps({ tone:{type:String,default:'info'}, message:String });</script>
<template><div v-if="message" class="admin-status" :class="`is-${tone}`">{{ message }}</div></template>
