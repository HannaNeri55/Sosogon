// frontend/src/pages/admin/ManageDestinations.jsx — full file
import { useEffect, useMemo, useRef, useState } from "react";
import {
  FiSearch,
  FiEdit2,
  FiTrash2,
  FiEye,
  FiMapPin,
  FiStar,
} from "react-icons/fi";
import {
  getAdminDestinations,
  createDestination,
  updateDestination,
  deleteDestination,
  getMunicipalities,
  getCategories,
} from "../../api/admin";
import { getImageUrl, oneTimeToken } from "../../utils/helpers";
import LoadingSpinner from "../../components/common/LoadingSpinner";
import DestinationPhotoManager from "../../components/admin/DestinationPhotoManager";
import DestinationActivitiesEditor from "../../components/admin/DestinationActivitiesEditor";
import { activityDraft, draftDurationMinutes, releaseActivityPreviews } from "../../utils/activityDrafts";

const MAX_NEW_PHOTOS_PER_SAVE = 10;
import toast from "react-hot-toast";
import MapLocationPicker from "../../components/maps/MapLocationPicker";
import useScrollToForm from "../../hooks/useScrollToForm";
import { showApiError } from "../../utils/apiErrors";
import { confirmDialog } from "../../utils/dialogs";
import useFormErrors from "../../hooks/useFormErrors";
import FieldError, { FormErrorSummary } from "../../components/common/FieldError";
import useAdminScope from "../../hooks/useAdminScope";

const PRIORITY_LABELS = {
  iconic: "Must-Visit / Iconic",
  popular: "Popular Attraction",
  recommended: "Recommended Attraction",
  hidden_gem: "Hidden Gem",
};

const EMPTY_FORM = {
  name: "",
  description: "",
  category_id: "",
  municipality_id: "1",
  address: "",
  latitude: "",
  longitude: "",
  operating_hours: "",
  entrance_fee: "",
  contact_number: "",
  website: "",
  status: "published",
  is_featured: false,
  tourism_priority: "",
  setting: "",

  fees: [],
  rates: [],
  amenities: [],
  activities: [],
};

export default function ManageDestinations() {
  // A municipal admin adds and edits their own municipality's destinations; Featured and Tourism
  // priority rank places across the province, so only provincial admins set them.
  const scope = useAdminScope();
  const ownTown = scope.municipality ? String(scope.municipality.id) : null;
  const blankForm = () => ({ ...EMPTY_FORM, municipality_id: ownTown ?? EMPTY_FORM.municipality_id });
  const [items, setItems] = useState([]);
  const [municipalities, setMunicipalities] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [formData, setFormData] = useState(EMPTY_FORM);
  const [existingImages, setExistingImages] = useState([]);
  // Staged photo changes, applied only when the form is saved.
  const [newPhotos, setNewPhotos] = useState([]); // [{ key, file, url }]
  const [removedImageIds, setRemovedImageIds] = useState([]);
  const [coverChoice, setCoverChoice] = useState(null); // "e:<id>" | "n:<key>" | null
  // A save (with photo uploads) can take several seconds. The ref blocks a
  // second submit immediately; the state drives the disabled button.
  const savingRef = useRef(false);
  const [saving, setSaving] = useState(false);
  const [search, setSearch] = useState("");

  const filteredItems = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return items;
    return items.filter(
      (item) =>
        item.name?.toLowerCase().includes(q) ||
        item.category?.name?.toLowerCase().includes(q),
    );
  }, [items, search]);

  const formRef = useScrollToForm(showForm ? editingId ?? "new" : null);
  const form = useFormErrors(formRef);

  const loadData = async () => {
    try {
      const data = await getAdminDestinations({ per_page: 100 });
      setItems(data.data || []);
    } finally {
      setLoading(false);
    }
  };

  const loadMeta = async () => {
    const [m, c] = await Promise.all([
      getMunicipalities(),
      getCategories("destination"),
    ]);
    setMunicipalities(m);
    setCategories(c);
  };

  useEffect(() => {
    loadData();
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadMeta();
  }, []);

  const startEdit = (item) => {
    setEditingId(item.id);

    setFormData({
      name: item.name || "",
      description: item.description || "",
      category_id: item.category_id || "",
      municipality_id: item.municipality_id || "1",
      address: item.address || "",
      latitude: item.latitude ?? "",
      longitude: item.longitude ?? "",
      operating_hours: item.operating_hours || "",
      entrance_fee: item.entrance_fee ?? "",
      contact_number: item.contact_number || "",
      website: item.website || "",
      status: item.status || "published",
      is_featured: !!item.is_featured,
      tourism_priority: item.tourism_priority || "",
      setting: item.setting || "",

      fees: (item.fees || []).map((fee) => ({
        label: fee.label || "",
        amount: fee.amount ?? "",
        description: fee.description || "",
      })),

      rates: (item.rates || []).map((rate) => ({
        label: rate.label || "",
        amount: rate.amount ?? "",
        description: rate.description || "",
      })),

      amenities: (item.amenities || []).map((amenity) => ({
        name: amenity.name || "",
      })),

      activities: (item.activities || []).map((activity) => activityDraft(activity)),
    });

    setExistingImages(item.images || []);
    resetPhotoChanges();
    setShowForm(true);
  };

    const addDetail = (type) => {
      const newItem =
        type === "amenities"
          ? { name: "" }
          : { label: "", amount: "", description: "" };

      setFormData((prev) => ({
        ...prev,
        [type]: [...prev[type], newItem],
      }));
    };

    const updateDetail = (type, index, field, value) => {
      setFormData((prev) => ({
        ...prev,
        [type]: prev[type].map((item, i) =>
          i === index ? { ...item, [field]: value } : item,
        ),
      }));
    };

    const removeDetail = (type, index) => {
      setFormData((prev) => ({
        ...prev,
        [type]: prev[type].filter((_, i) => i !== index),
      }));
    };

  const resetPhotoChanges = () => {
    setNewPhotos((prev) => {
      prev.forEach((photo) => URL.revokeObjectURL(photo.url));
      return [];
    });
    setRemovedImageIds([]);
    setCoverChoice(null);
  };

  const closeForm = () => {
    form.setErrors({});
    releaseActivityPreviews(formData.activities);
    setShowForm(false);
    setEditingId(null);
    setFormData(blankForm());
    setExistingImages([]);
    resetPhotoChanges();
  };

  const addPhotos = (files) => {
    const room = MAX_NEW_PHOTOS_PER_SAVE - newPhotos.length;
    if (files.length > room) {
      form.setErrors({ images: `You can add up to ${MAX_NEW_PHOTOS_PER_SAVE} new photos per save.` });
    } else {
      form.clear("images");
    }
    const added = files.slice(0, Math.max(0, room)).map((file) => ({
      key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
      file,
      url: URL.createObjectURL(file),
    }));
    setNewPhotos((prev) => [...prev, ...added]);
  };

  const removeNewPhoto = (key) => {
    setNewPhotos((prev) => {
      const photo = prev.find((p) => p.key === key);
      if (photo) URL.revokeObjectURL(photo.url);
      return prev.filter((p) => p.key !== key);
    });
    if (coverChoice === `n:${key}`) setCoverChoice(null);
  };

  const removeExistingPhoto = (id) => {
    setRemovedImageIds((prev) => [...prev, id]);
    if (coverChoice === `e:${id}`) setCoverChoice(null);
  };

  // The cover shown and saved: the admin's choice if still present, otherwise
  // the current saved cover, otherwise the first remaining photo.
  const visibleExisting = existingImages.filter((img) => !removedImageIds.includes(img.id));
  const photoKeys = [
    ...visibleExisting.map((img) => `e:${img.id}`),
    ...newPhotos.map((photo) => `n:${photo.key}`),
  ];
  const savedCover = visibleExisting.find((img) => img.is_primary);
  const coverKey = photoKeys.includes(coverChoice)
    ? coverChoice
    : savedCover
      ? `e:${savedCover.id}`
      : photoKeys[0] ?? null;

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!form.check()) return;
    if (savingRef.current) return;
    savingRef.current = true;
    setSaving(true);
    try {
      const payload = new FormData();
      // One-time token: the server refuses a repeat of this exact save.
      payload.append("save_token", oneTimeToken());

      // Regular destination fields
      Object.keys(formData).forEach((key) => {
        if (["images", "fees", "rates", "amenities", "activities"].includes(key)) return;
        if (scope.isMunicipal && ["is_featured", "tourism_priority"].includes(key)) return;

        // Sent even when empty, so a classification can be cleared.
        if (["tourism_priority", "setting"].includes(key)) {
          payload.append(key, formData[key] || "");
          return;
        }

        if (formData[key] !== "" && formData[key] !== null) {
          payload.append(
            key,
            key === "is_featured" ? (formData[key] ? "1" : "0") : formData[key],
          );
        }
      });

      // Tell Laravel which detail sections should be replaced,
      // even when the arrays are empty.
      payload.append("details_present", "1");

      // Multiple entrance fees
      formData.fees.forEach((fee, index) => {
        payload.append(`fees[${index}][label]`, fee.label);
        payload.append(`fees[${index}][amount]`, fee.amount);
        payload.append(`fees[${index}][description]`, fee.description || "");
      });

      // Cottage and rental rates
      formData.rates.forEach((rate, index) => {
        payload.append(`rates[${index}][label]`, rate.label);
        payload.append(`rates[${index}][amount]`, rate.amount);
        payload.append(`rates[${index}][description]`, rate.description || "");
      });

      // Amenities
      formData.amenities.forEach((amenity, index) => {
        payload.append(`amenities[${index}][name]`, amenity.name);
      });

      // Activities (optional). The full list is sent so removed ones are deleted.
      payload.append("activities_present", "1");
      formData.activities.forEach((activity, index) => {
        const field = (name) => `activities[${index}][${name}]`;
        if (activity.id) payload.append(field("id"), activity.id);
        payload.append(field("name"), activity.name.trim());
        payload.append(field("description"), activity.description || "");
        payload.append(field("type"), activity.type);
        payload.append(field("price"), activity.price ?? "");
        payload.append(field("duration_minutes"), draftDurationMinutes(activity));
        payload.append(field("capacity"), activity.capacity ?? "");
        if (activity.newImage) payload.append(field("image"), activity.newImage);
        else if (activity.removeImage) payload.append(field("remove_image"), "1");
      });

      // Staged photo changes: new uploads, removals and the cover choice.
      newPhotos.forEach((photo) => payload.append("images[]", photo.file));
      removedImageIds.forEach((id) => payload.append("remove_image_ids[]", id));
      if (coverKey?.startsWith("n:")) {
        payload.append(
          "cover_new_index",
          newPhotos.findIndex((photo) => `n:${photo.key}` === coverKey),
        );
      } else if (coverKey?.startsWith("e:") && editingId) {
        payload.append("cover_image_id", coverKey.slice(2));
      }

      if (editingId) {
        await updateDestination(editingId, payload);
        toast.success("Destination updated");
      } else {
        await createDestination(payload);
        toast.success("Destination created");
      }
      closeForm();
      loadData();
    } catch (e) {
      if (!form.fromResponse(e)) showApiError(e, editingId ? "Failed to update destination" : "Failed to create destination");
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  };

  const handleDelete = async (id) => {
    if (!(await confirmDialog({ title: "Delete this destination?", message: "Its photos and activities will be removed too. This cannot be undone.", confirmLabel: "Delete", danger: true }))) return;
    try {
      await deleteDestination(id);
      toast.success("Destination deleted");
      loadData();
    } catch (error) {
      showApiError(error, "Failed to delete destination");
    }
  };

  if (loading) return <LoadingSpinner size="lg" />;

  return (
    <div>
      <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
        <div>
          <p className="text-primary-600 font-medium mb-2">Admin</p>
          <h1 className="text-2xl sm:text-3xl font-heading font-bold text-gray-900">
            Manage Destinations
          </h1>
        </div>
        <button
          onClick={() => {
            if (showForm) {
              closeForm();
            } else {
              setFormData(blankForm());
              setShowForm(true);
            }
          }}
          disabled={saving}
          className="btn-primary disabled:opacity-60"
        >
          {showForm ? "Close Form" : "Add Destination"}
        </button>
      </div>

      {showForm && (
        <form
          ref={formRef}
          noValidate
          onChange={form.onChange}
          onSubmit={handleSubmit}
          className="bg-white rounded-xl p-5 sm:p-8 border border-gray-100 mb-8 space-y-5"
        >
          <p className="text-sm font-medium text-gray-500">
            {editingId ? "Editing destination" : "New destination"}
          </p>
          <FormErrorSummary form={form} />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <input name="name" aria-label="Destination name" maxLength={255}
                className="input-field"
                placeholder="Destination name"
                value={formData.name}
                onChange={(e) =>
                  setFormData({ ...formData, name: e.target.value })
                }
                required
              />
              <FieldError form={form} name="name" />
            </div>
            <div>
              <select name="category_id" aria-label="Category"
                className="input-field"
                value={formData.category_id}
                onChange={(e) =>
                  setFormData({ ...formData, category_id: e.target.value })
                }
                required
              >
                <option value="">Select category</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
              <FieldError form={form} name="category_id" />
            </div>
          </div>
          <div>
            <textarea name="description" aria-label="Description"
              className="input-field"
              rows={4}
              placeholder="Description"
              value={formData.description}
              onChange={(e) =>
                setFormData({ ...formData, description: e.target.value })
              }
              required
            />
            <FieldError form={form} name="description" />
          </div>
          <div>
            <input name="address" aria-label="Address" maxLength={500}
              className="input-field"
              placeholder="Address"
              value={formData.address}
              onChange={(e) =>
                setFormData({ ...formData, address: e.target.value })
              }
              required
            />
            <FieldError form={form} name="address" />
          </div>
          <div>
            <select name="municipality_id" aria-label="Municipality" required
              className="input-field disabled:bg-gray-50 disabled:text-gray-600"
              value={formData.municipality_id}
              disabled={scope.isMunicipal}
              onChange={(e) =>
                setFormData({ ...formData, municipality_id: e.target.value })
              }
            >
              {municipalities.filter((m) => scope.canManage(m.id)).map((m) => (
                <option key={m.id} value={m.id}>
                  {m.name}
                </option>
              ))}
            </select>
            {scope.isMunicipal && (
              <p className="mt-1 text-xs text-gray-500">Your office manages destinations in {scope.municipality?.name} only.</p>
            )}
            <FieldError form={form} name="municipality_id" />
          </div>
          <fieldset className="rounded-xl border border-gray-200 p-4 sm:p-5">
            <legend className="px-2 text-sm font-semibold text-gray-800">Exact location on the map</legend>
            <MapLocationPicker
              required
              latitude={formData.latitude}
              longitude={formData.longitude}
              searchHint={formData.name}
              onChange={({ latitude, longitude }) => setFormData((prev) => ({ ...prev, latitude, longitude }))}
              onAddressFound={(found) => setFormData((prev) => (prev.address ? prev : { ...prev, address: found }))}
            />
            <FieldError form={form} name="latitude" />
            <FieldError form={form} name="longitude" />
          </fieldset>
          <div>
            <input name="operating_hours" aria-label="Operating hours" maxLength={255}
              className="input-field"
              placeholder="Operating hours"
              value={formData.operating_hours}
              onChange={(e) =>
                setFormData({ ...formData, operating_hours: e.target.value })
              }
            />
            <FieldError form={form} name="operating_hours" />
          </div>
          <DestinationPhotoManager
            existingImages={existingImages}
            removedImageIds={removedImageIds}
            newPhotos={newPhotos}
            coverKey={coverKey}
            isEditing={Boolean(editingId)}
            onAddFiles={addPhotos}
            onRemoveExisting={removeExistingPhoto}
            onUndoRemovals={() => setRemovedImageIds([])}
            onRemoveNew={removeNewPhoto}
            onSetCover={setCoverChoice}
          />
          <FieldError form={form} name="images" />
          <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
            
            <div>
              <input name="contact_number" aria-label="Contact number" maxLength={50}
                className="input-field"
                placeholder="Contact number"
                value={formData.contact_number}
                onChange={(e) =>
                  setFormData({ ...formData, contact_number: e.target.value })
                }
              />
              <FieldError form={form} name="contact_number" />
            </div>
            <div>
              <select name="status" aria-label="Status"
                className="input-field"
                value={formData.status}
                onChange={(e) =>
                  setFormData({ ...formData, status: e.target.value })
                }
              >
                <option value="published">Published</option>
                <option value="draft">Draft</option>
              </select>
              <FieldError form={form} name="status" />
            </div>
          </div>
          <div>
            <input name="website" aria-label="Website" maxLength={500}
              type="url"
              className="input-field"
              placeholder="Website (optional)"
              value={formData.website}
              onChange={(e) =>
                setFormData({ ...formData, website: e.target.value })
              }
            />
            <FieldError form={form} name="website" />
          </div>

          {/* Entrance Fees */}
          <div className="border border-gray-200 rounded-xl p-5 space-y-4">
            <div className="flex items-center justify-between gap-3">
              <h3 className="font-semibold text-gray-900">Entrance Fees</h3>
              <button
                type="button"
                onClick={() => addDetail("fees")}
                className="btn-primary"
              >
                + Add Entrance Fee
              </button>
            </div>

            {formData.fees.map((fee, index) => (
              <div
                key={index}
                className="grid grid-cols-1 md:grid-cols-4 gap-3 items-start"
              >
                <div>
                  <input name={`fees.${index}.label`} aria-label="Name" maxLength={255}
                    className="input-field"
                    placeholder="Fee name (e.g. Adult)"
                    value={fee.label}
                    onChange={(e) =>
                      updateDetail("fees", index, "label", e.target.value)
                    }
                    required
                  />
                  <FieldError form={form} name={`fees.${index}.label`} />
                </div>

                <div>
                  <input name={`fees.${index}.amount`} aria-label="Amount"
                    type="number"
                    min="0"
                    step="0.01"
                    className="input-field"
                    placeholder="Amount (PHP)"
                    value={fee.amount}
                    onChange={(e) =>
                      updateDetail("fees", index, "amount", e.target.value)
                    }
                    required
                  />
                  <FieldError form={form} name={`fees.${index}.amount`} />
                </div>

                <div>
                  <input name={`fees.${index}.description`} aria-label="Description" maxLength={255}
                    className="input-field"
                    placeholder="Description (optional)"
                    value={fee.description}
                    onChange={(e) =>
                      updateDetail("fees", index, "description", e.target.value)
                    }
                  />
                  <FieldError form={form} name={`fees.${index}.description`} />
                </div>

                <button
                  type="button"
                  onClick={() => removeDetail("fees", index)}
                  className="px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                >
                  Remove
                </button>
              </div>
            ))}

            {formData.fees.length === 0 && (
              <p className="text-sm text-gray-500">
                No entrance fees added yet.
              </p>
            )}
          </div>

          {/* Cottage / Rental Rates */}
          <div className="border border-gray-200 rounded-xl p-5 space-y-4">
            <div className="flex items-center justify-between gap-3">
              <h3 className="font-semibold text-gray-900">
                Cottage / Rental Rates
              </h3>
              <button
                type="button"
                onClick={() => addDetail("rates")}
                className="btn-primary"
              >
                + Add Rental Rate
              </button>
            </div>

            {formData.rates.map((rate, index) => (
              <div
                key={index}
                className="grid grid-cols-1 md:grid-cols-4 gap-3 items-start"
              >
                <div>
                  <input name={`rates.${index}.label`} aria-label="Name" maxLength={255}
                    className="input-field"
                    placeholder="Rate name (e.g. Cubo Cottage)"
                    value={rate.label}
                    onChange={(e) =>
                      updateDetail("rates", index, "label", e.target.value)
                    }
                    required
                  />
                  <FieldError form={form} name={`rates.${index}.label`} />
                </div>

                <div>
                  <input name={`rates.${index}.amount`} aria-label="Amount"
                    type="number"
                    min="0"
                    step="0.01"
                    className="input-field"
                    placeholder="Amount (PHP)"
                    value={rate.amount}
                    onChange={(e) =>
                      updateDetail("rates", index, "amount", e.target.value)
                    }
                    required
                  />
                  <FieldError form={form} name={`rates.${index}.amount`} />
                </div>

                <div>
                  <input name={`rates.${index}.description`} aria-label="Description" maxLength={255}
                    className="input-field"
                    placeholder="Description (optional)"
                    value={rate.description}
                    onChange={(e) =>
                      updateDetail("rates", index, "description", e.target.value)
                    }
                  />
                  <FieldError form={form} name={`rates.${index}.description`} />
                </div>

                <button
                  type="button"
                  onClick={() => removeDetail("rates", index)}
                  className="px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                >
                  Remove
                </button>
              </div>
            ))}

            {formData.rates.length === 0 && (
              <p className="text-sm text-gray-500">
                No rental rates added yet.
              </p>
            )}
          </div>

          {/* Amenities */}
          <div className="border border-gray-200 rounded-xl p-5 space-y-4">
            <div className="flex items-center justify-between gap-3">
              <h3 className="font-semibold text-gray-900">Amenities</h3>
              <button
                type="button"
                onClick={() => addDetail("amenities")}
                className="btn-primary"
              >
                + Add Amenity
              </button>
            </div>

            {formData.amenities.map((amenity, index) => (
              <div key={index} className="flex flex-wrap gap-3">
                <div className="flex-1">
                  <input name={`amenities.${index}.name`} aria-label="Amenity" maxLength={255}
                    className="input-field"
                    placeholder="Amenity (e.g. Swimming Pool)"
                    value={amenity.name}
                    onChange={(e) =>
                      updateDetail("amenities", index, "name", e.target.value)
                    }
                    required
                  />
                  <FieldError form={form} name={`amenities.${index}.name`} />
                </div>

                <button
                  type="button"
                  onClick={() => removeDetail("amenities", index)}
                  className="px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50"
                >
                  Remove
                </button>
              </div>
            ))}

            {formData.amenities.length === 0 && (
              <p className="text-sm text-gray-500">No amenities added yet.</p>
            )}
          </div>

          {/* Activities (optional, saved with the destination) */}
          <DestinationActivitiesEditor
            activities={formData.activities}
            onChange={(activities) => setFormData((prev) => ({ ...prev, activities }))}
            form={form}
          />

          {scope.isProvincial ? (
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                name="is_featured"
                checked={formData.is_featured}
                onChange={(e) =>
                  setFormData({ ...formData, is_featured: e.target.checked })
                }
              />
              Featured destination (shown on the homepage)
            </label>
          ) : (
            <p className="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600">
              Featured: <span className="font-medium text-gray-800">{formData.is_featured ? "Yes" : "No"}</span>
              {" · "}Tourism priority:{" "}
              <span className="font-medium text-gray-800">{PRIORITY_LABELS[formData.tourism_priority] || "Not classified"}</span>
              <span className="mt-1 block text-xs text-gray-500">
                These rank places across the whole province, so the provincial administrator sets them.
              </span>
            </p>
          )}

          {/* What the itinerary planner relies on instead of guessing. */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {scope.isProvincial && (
            <label className="block">
              <span className="block text-sm font-medium text-gray-700 mb-1">Tourism priority</span>
              <select
                name="tourism_priority"
                value={formData.tourism_priority}
                onChange={(e) => setFormData({ ...formData, tourism_priority: e.target.value })}
                className="input-field"
              >
                <option value="">Not classified</option>
                {Object.entries(PRIORITY_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
              <span className="mt-1 block text-xs text-gray-500">The AI trip planner builds days around Must-Visit and Popular places first.</span>
            </label>
            )}
            <label className="block">
              <span className="block text-sm font-medium text-gray-700 mb-1">Setting</span>
              <select
                name="setting"
                value={formData.setting}
                onChange={(e) => setFormData({ ...formData, setting: e.target.value })}
                className="input-field"
              >
                <option value="">Not specified</option>
                <option value="indoor">Indoor</option>
                <option value="outdoor">Open-air / outdoor</option>
                <option value="mixed">Partly covered</option>
              </select>
              <span className="mt-1 block text-xs text-gray-500">Only Indoor places are suggested as rainy-day alternatives.</span>
            </label>
          </div>
          <div className="flex gap-3">
            <button
              type="submit"
              className="btn-primary disabled:cursor-wait disabled:opacity-60"
              disabled={saving}
              aria-busy={saving}
            >
              {saving
                ? newPhotos.length
                  ? `Saving… uploading ${newPhotos.length} photo${newPhotos.length === 1 ? "" : "s"}`
                  : "Saving…"
                : editingId
                  ? "Save Changes"
                  : "Save Destination"}
            </button>
            {editingId && (
              <button
                type="button"
                onClick={closeForm}
                disabled={saving}
                className="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 disabled:opacity-50"
              >
                Cancel
              </button>
            )}
          </div>
        </form>
      )}

      <div className="relative mb-5 max-w-sm">
        <FiSearch
          className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"
          size={16}
        />
        <input
          className="input-field pl-9"
          placeholder="Search destinations..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="bg-white rounded-2xl border border-gray-200/70 shadow-(--shadow-card) overflow-hidden">
        <div className="overflow-x-auto">
          {/* Phones: keep columns readable and let the table scroll sideways. */}
          <table className="table-cards w-full min-w-[40rem] [&_.rounded-full]:whitespace-nowrap">
            <thead className="bg-gray-50/80 border-b border-gray-200">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Destination
                </th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Category
                </th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Status
                </th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Featured
                </th>
                <th className="text-left px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Views
                </th>
                <th className="text-right px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr
                  key={item.id}
                  className="border-b border-gray-100 last:border-b-0 hover:bg-primary-50/40 transition-colors duration-150"
                >
                  <td className="px-6 py-3">
                    <div className="flex items-center gap-3">
                      <img
                        src={getImageUrl(item.images?.find((img) => img.is_primary) || item.images?.[0])}
                        alt=""
                        className="w-10 h-10 rounded-lg object-cover shrink-0 bg-gray-100"
                      />
                      <div>
                        <p className="text-sm font-medium text-gray-900">
                          {item.name}
                        </p>
                        <p className="text-xs text-gray-400 flex items-center gap-1 mt-0.5">
                          <FiMapPin size={10} />
                          {item.municipality?.name}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td data-label="Category" className="px-6 py-4">
                    <span className="text-xs font-medium px-2.5 py-1 rounded-full bg-secondary-50 text-secondary-700">
                      {item.category?.name}
                    </span>
                  </td>
                  <td data-label="Status" className="px-6 py-4">
                    <span
                      className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${item.status === "published" ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-600"}`}
                    >
                      {item.status}
                    </span>
                  </td>
                  <td data-label="Featured" className="px-6 py-4 text-sm text-gray-600">
                    {item.is_featured ? (
                      <FiStar
                        className="text-yellow-500 fill-yellow-500"
                        size={15}
                      />
                    ) : (
                      "—"
                    )}
                  </td>
                  <td data-label="Views" className="px-6 py-4 text-sm text-gray-600">
                    <span className="flex items-center gap-1.5">
                      <FiEye size={13} className="text-gray-400" />
                      {item.view_count}
                    </span>
                  </td>
                  <td data-actions className="px-6 py-4">
                    <div className="flex gap-3 justify-end">
                      <button
                        onClick={() => startEdit(item)}
                        className="p-2 text-gray-400 hover:text-primary-600 hover:bg-primary-50 rounded-lg transition-colors"
                        title="Edit"
                      >
                        <FiEdit2 size={15} />
                      </button>
                      <button
                        onClick={() => handleDelete(item.id)}
                        className="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                        title="Delete"
                      >
                        <FiTrash2 size={15} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
