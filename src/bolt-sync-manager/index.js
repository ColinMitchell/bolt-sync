import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { Suspense } from '@wordpress/element';
import { plus } from '@wordpress/icons';
import BoltSyncManager from '../components/BoltSyncManager';
import LoadingFallback from '../components/LoadingFallback';

function PluginDocumentSettingPanelDemo() {
    return (
        <PluginDocumentSettingPanel
            name="bolt-sync-manager"
            title="Bolt Sync Manager"
            className="bolt-sync-manager"
            initialOpen={true}
        >
            <Suspense fallback={<LoadingFallback />}>
                <BoltSyncManager />
            </Suspense>
        </PluginDocumentSettingPanel>
    );
}

registerPlugin('bolt-sync-manager', {
    render: PluginDocumentSettingPanelDemo,
    icon: plus,
});

export default PluginDocumentSettingPanelDemo;
