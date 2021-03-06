import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action, computed, set } from '@ember/object';
import { gt } from '@ember/object/computed';

export default class OpenXpkiController extends Controller {
    // Reserved Ember properties
    // https://api.emberjs.com/ember/release/classes/Controller
    queryParams = [
        "count",
        "limit",
        "startat",
        "force" // supported query parameters, available as this.count etc.
    ];

    // FIXME Remove those three?! (auto-injected by Ember, see queryParams above)
    count = null;
    startat = null;
    limit = null;

    @tracked loading = false;

    @computed("model.status.{level,message}")
    get statusClass() {
        let level = this.get("model.status.level");
        let message = this.get("model.status.message");
        if (!message) { return "hide" }
        if (level === "error") { return "alert-danger" }
        if (level === "success") { return "alert-success" }
        if (level === "warn") { return "alert-warning" }
        return "alert-info";
    }

    @gt("model.tabs.length", 1) showTabs;

    @action
    activateTab(entry) {
        let tabs = this.get("model.tabs");
        tabs.setEach("active", false);
        set(entry, "active", true);
        return false;
    }

    @action
    closeTab(entry) {
        let tabs = this.get("model.tabs");
        tabs.removeObject(entry);
        if (!tabs.findBy("active", true)) {
            tabs.set("lastObject.active", true);
        }
        return false;
    }

    @action
    reload() {
        return window.location.reload();
    }

    @action
    clearPopupData() {
        return this.set("model.popup", null);
    }
}