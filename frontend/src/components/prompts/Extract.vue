<template>
  <div class="card floating">
    <div class="card-title">
      <h2>Extract Archive</h2>
    </div>

    <div class="card-content">
      <p v-if="!extracting && !done">
        Extract <code>{{ archiveName }}</code>?
        <br />
        <span style="font-size: 0.9em; color: #666">
          Files will be extracted to a folder named after the archive.
          Path traversal is automatically blocked.
        </span>
      </p>
      <p v-if="extracting" style="display: flex; align-items: center; gap: 10px">
        <span class="spinner"></span>
        Extracting…
      </p>
      <p v-if="done" style="color: #28a745">
        ✓ Extracted to: <code>{{ destination }}</code>
      </p>
      <p v-if="error" style="color: #dc3545">{{ error }}</p>
    </div>

    <div class="card-action">
      <button
        v-if="!done"
        class="button button--flat button--grey"
        @click="closeHovers"
        :disabled="extracting"
      >
        Cancel
      </button>
      <button
        v-if="!done"
        @click="submit"
        class="button button--flat"
        type="submit"
        :disabled="extracting"
      >
        {{ extracting ? "Extracting…" : "Extract" }}
      </button>
      <button v-if="done" @click="closeAndReload" class="button button--flat">
        Close
      </button>
    </div>
  </div>
</template>

<script>
import { mapActions, mapState, mapWritableState } from "pinia";
import { useFileStore } from "@/stores/file";
import { useLayoutStore } from "@/stores/layout";
import { extract } from "@/api/files";

export default {
  name: "extract",
  data() {
    return {
      extracting: false,
      done: false,
      error: "",
      destination: "",
    };
  },
  inject: ["$showError"],
  computed: {
    ...mapState(useFileStore, ["req", "selected", "selectedCount", "isListing"]),
    ...mapWritableState(useFileStore, ["reload"]),
    archiveName() {
      if (!this.isListing) return this.req.name;
      if (this.selectedCount !== 1) return "";
      return this.req.items[this.selected[0]].name;
    },
    archiveUrl() {
      if (!this.isListing) return this.req.url;
      return this.req.items[this.selected[0]].url;
    },
  },
  methods: {
    ...mapActions(useLayoutStore, ["closeHovers"]),
    async submit() {
      this.extracting = true;
      this.error = "";
      try {
        const result = await extract(this.archiveUrl);
        this.done = true;
        this.destination = result.destination || "";
      } catch (e) {
        this.error = e.message || "Extraction failed";
        this.$showError(e);
      } finally {
        this.extracting = false;
      }
    },
    closeAndReload() {
      this.reload = true;
      this.closeHovers();
    },
  },
};
</script>

<style scoped>
.spinner {
  width: 16px;
  height: 16px;
  border: 2px solid #ccc;
  border-top-color: #2196f3;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
  display: inline-block;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
