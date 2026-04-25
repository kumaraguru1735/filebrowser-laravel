<template>
  <div class="card floating">
    <div class="card-title">
      <h2>Extract Archive</h2>
    </div>

    <div class="card-content" v-if="!extracting && !done">
      <p>
        Extract <code>{{ archiveName }}</code> to:
      </p>
      <file-list
        ref="fileList"
        @update:selected="(val) => (dest = val)"
        tabindex="1"
      />
      <div style="margin-top: 12px">
        <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9em">
          <input type="checkbox" v-model="overwrite" />
          Overwrite existing files
        </label>
      </div>
    </div>

    <div class="card-content" v-else-if="extracting">
      <p style="display: flex; align-items: center; gap: 10px">
        <span class="spinner"></span>
        Extracting…
      </p>
    </div>

    <div class="card-content" v-else-if="done">
      <p style="color: #28a745">
        ✓ Extracted to: <code>{{ destination }}</code>
      </p>
    </div>

    <div v-if="error" class="card-content" style="color: #dc3545">{{ error }}</div>

    <div
      class="card-action"
      style="display: flex; align-items: center; justify-content: space-between"
    >
      <template v-if="!done && !extracting && user.perm.create">
        <button
          class="button button--flat"
          @click="$refs.fileList.createDir()"
          :aria-label="$t('sidebar.newFolder')"
          :title="$t('sidebar.newFolder')"
        >
          <span>{{ $t("sidebar.newFolder") }}</span>
        </button>
      </template>
      <div :style="{ marginLeft: 'auto' }">
        <button
          v-if="!done"
          class="button button--flat button--grey"
          @click="closeHovers"
          :disabled="extracting"
        >
          {{ $t("buttons.cancel") }}
        </button>
        <button
          v-if="!done"
          @click="submit"
          class="button button--flat"
          :disabled="extracting || dest === null"
        >
          {{ extracting ? "Extracting…" : "Extract" }}
        </button>
        <button v-if="done" @click="closeAndReload" class="button button--flat">
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { mapActions, mapState, mapWritableState } from "pinia";
import { useFileStore } from "@/stores/file";
import { useLayoutStore } from "@/stores/layout";
import { useAuthStore } from "@/stores/auth";
import FileList from "./FileList.vue";
import { extract } from "@/api/files";

export default {
  name: "extract",
  components: { FileList },
  data() {
    return {
      dest: null,
      overwrite: false,
      extracting: false,
      done: false,
      error: "",
      destination: "",
    };
  },
  inject: ["$showError"],
  computed: {
    ...mapState(useFileStore, ["req", "selected", "selectedCount", "isListing"]),
    ...mapState(useAuthStore, ["user"]),
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
      if (this.dest === null) return;
      this.extracting = true;
      this.error = "";
      try {
        const result = await extract(this.archiveUrl, this.dest, this.overwrite);
        this.done = true;
        this.destination = result.destination || this.dest;
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
