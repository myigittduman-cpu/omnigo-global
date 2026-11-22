#include <windows.h>
#include <winrt/Windows.ApplicationModel.DataTransfer.h>
#include <winrt/Windows.Foundation.Collections.h>
#include <winrt/Windows.Foundation.Metadata.h>
#include <winrt/Windows.Storage.h>
#include <winrt/Windows.UI.h>
#include <array>
#include <chrono>
#include <condition_variable>
#include <memory>
#include <mutex>

using namespace winrt;
using namespace Windows::ApplicationModel::DataTransfer;

struct Discovery {
    std::mutex mutex;
    std::condition_variable changed;
    TransferTarget target{nullptr};
    bool complete = false;
};

// 0 permits the system picker. After handoff starts, never launch a second transfer.
extern "C" int omnigo_share_whatsapp(const wchar_t* path, uintptr_t owner) noexcept {
    bool invoked = false;
    try {
        init_apartment(apartment_type::multi_threaded);
        struct Apartment { ~Apartment() { uninit_apartment(); } } apartment;
        if (!Windows::Foundation::Metadata::ApiInformation::IsTypePresent(
                L"Windows.ApplicationModel.DataTransfer.TransferTargetWatcher")) return 0;
        auto file = Windows::Storage::StorageFile::GetFileFromPathAsync(path).get();
        DataPackage data;
        data.Properties().Title(file.Name());
        data.RequestedOperation(DataPackageOperation::Copy);
        data.SetStorageItems(single_threaded_vector<Windows::Storage::IStorageItem>({file}), true);
        TransferTargetDiscoveryOptions options(data.GetView());
        options.AllowedTargetAppIds(std::array<hstring, 1>{L"5319275A.WhatsAppDesktop_cv1g1gvanyjgm!App"});
        options.MaxAppTargets(1);
        auto watcher = TransferTarget::CreateWatcher(options);
        auto state = std::make_shared<Discovery>();
        auto receive = [state](auto const&, auto const& event) {
            auto item = event.Target();
            if (!item.IsEnabled()) return;
            std::lock_guard guard(state->mutex);
            state->target = item;
            state->changed.notify_one();
        };
        auto added = watcher.Added(auto_revoke, receive);
        auto updated = watcher.Updated(auto_revoke, receive);
        auto done = watcher.EnumerationCompleted(auto_revoke, [state](auto const&, auto const&) {
            std::lock_guard guard(state->mutex);
            state->complete = true;
            state->changed.notify_one();
        });
        watcher.Start();
        TransferTarget selected{nullptr};
        {
            std::unique_lock lock(state->mutex);
            state->changed.wait_for(lock, std::chrono::seconds(5), [&] { return state->target || state->complete; });
            selected = state->target;
        }
        if (!selected) { watcher.Stop(); return 0; }
        invoked = true;
        auto result = watcher.TransferToAsync(selected, Windows::UI::WindowId{owner}).get();
        return result.Succeeded() ? 1 : -1;
    } catch (...) { return invoked ? -1 : 0; }
}
