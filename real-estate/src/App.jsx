import { useEffect, useRef, useState, useCallback } from "react";
import AuthView from "./components/AuthView";
import Header from "./components/Header";
import PropertyEditor from "./components/PropertyEditor";
import Sidebar from "./components/SideBar";
import Dashboard from "./pages/Dashboard";
import Enquiries from "./pages/Enquiries";
import Notifications from "./pages/Notifications";
import Payments from "./pages/Payments";
import People from "./pages/People";
import Properties from "./pages/Properties";
import Sales from "./pages/Sales";
import Rentals from "./pages/Rentals";
import { getCurrentUser, getMe, logout } from "./api/auth";
import {
  getProperties,
  getAdminProperties,
  getMyProperties,
  createProperty,
  updateProperty,
  addPropertyImage,
  requestPropertyVerification,
  uploadVerificationDocument,
} from "./api/properties";
import { getEnquiries, createEnquiry, updateEnquiryStatus } from "./api/enquiries";
import { getSales, createSale, getSaleInstallments, sendInstallmentReminder } from "./api/sales";
import { getAdminPayments, createPayment } from "./api/payments";
import { getNotificationLogs } from "./api/notifications";
import {
  getUsers,
  inviteUser,
  createAdminUser,
  approveUser,
  rejectUser,
  suspendUser,
  reactivateUser,
  getPendingDeletionRequests,
  approveDeletionRequest,
  rejectDeletionRequest,
} from "./api/users";
import { sanitize } from "./lib/validation";
import { getCompanyProfile, updateCompanyProfile, uploadCompanyLogo } from "./api/companyProfile";

const rolePages = {
  admin: ["Dashboard", "Properties", "Enquiries", "Agents", "Owners", "Sales", "Payments", "Rentals", "Notifications"],
  agent: ["Dashboard", "Properties", "Enquiries", "Rentals", "Sales", "Notifications"],
  owner: ["Dashboard", "Properties", "Enquiries", "Rentals", "Notifications"],
};

export default function App() {
  const [currentUser, setCurrentUser] = useState(() => getCurrentUser());
  const [authLoading, setAuthLoading] = useState(true);
  const [activePage, setActivePage] = useState("Dashboard");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [propertyEditorOpen, setPropertyEditorOpen] = useState(false);
  const [editingProperty, setEditingProperty] = useState(null);
  const [propertyDefaults, setPropertyDefaults] = useState({});
  const [highlightedSaleId, setHighlightedSaleId] = useState(null);
  const [toast, setToast] = useState("");
  const [companyProfile, setCompanyProfile] = useState({
    company_name: "Real Estate Portal",
    logo_url: null,
  });
  const toastTimer = useRef(null);

  // Live backend data states
  const [properties, setProperties] = useState([]);
  const [enquiries, setEnquiries] = useState([]);
  const [sales, setSales] = useState([]);
  const [payments, setPayments] = useState([]);
  const [notifications, setNotifications] = useState([]);
  const [agents, setAgents] = useState([]);
  const [owners, setOwners] = useState([]);
  const [admins, setAdmins] = useState([]);
  const [allUsers, setAllUsers] = useState([]);
  const [deletionRequests, setDeletionRequests] = useState([]);
  const [, setLoadingData] = useState(false);

  function notifyUser(message) {
    window.clearTimeout(toastTimer.current);
    setToast(message);
    toastTimer.current = window.setTimeout(() => setToast(""), 6000);
  }

  // 1. Check session validity on startup
  useEffect(() => {
    async function verifySession() {
      const stored = getCurrentUser();
      if (stored) {
        try {
          const res = await getMe();
          if (res.success && res.data?.user) {
            setCurrentUser(res.data.user);
          } else {
            setCurrentUser(null);
          }
        } catch {
          setCurrentUser(null);
        }
      }
      setAuthLoading(false);
    }
    verifySession();
  }, []);

  useEffect(() => {
    if (currentUser?.role !== "admin") return;
    let active = true;
    async function loadCompanyProfile() {
      const res = await getCompanyProfile();
      if (active && res.success && res.data?.profile) {
        setCompanyProfile(res.data.profile);
      } else if (active && !res.success) {
        notifyUser(res.message || "Unable to load company profile.");
      }
    }
    loadCompanyProfile();
    return () => {
      active = false;
    };
  }, [currentUser?.id, currentUser?.role]);

  // 2. Fetch live data from backend
  const loadBackendData = useCallback(async () => {
    if (!currentUser) return;
    setLoadingData(true);

    try {
      const isAdmin = currentUser.role === "admin";

      // Properties
      let rawProperties = [];
      if (isAdmin) {
        const propRes = await getAdminProperties({ limit: 100 });
        if (propRes.success) {
          rawProperties = propRes.data?.data || (Array.isArray(propRes.data) ? propRes.data : []);
        }
      } else if (currentUser.role === "owner") {
        const myRes = await getMyProperties({ limit: 100 });
        if (myRes.success) {
          rawProperties = myRes.data?.data || (Array.isArray(myRes.data) ? myRes.data : []);
        }
      } else {
        const [publicRes, myRes] = await Promise.all([
          getProperties({ limit: 100 }),
          getMyProperties({ limit: 100 }),
        ]);
        const pubList = publicRes.success
          ? publicRes.data?.data || (Array.isArray(publicRes.data) ? publicRes.data : [])
          : [];
        const myList = myRes.success
          ? myRes.data?.data || (Array.isArray(myRes.data) ? myRes.data : [])
          : [];
        const mergedMap = new Map();
        [...myList, ...pubList].forEach((p) => {
          if (p && p.id) mergedMap.set(p.id, p);
        });
        rawProperties = Array.from(mergedMap.values());
      }

      rawProperties.sort((a, b) => Number(b.id || 0) - Number(a.id || 0));

      if (rawProperties.length >= 0) {
        const formatted = rawProperties.map((p) => ({
          id: p.id,
          title: p.title,
          description: p.description,
          type: (p.property_type || "house").replace(/^\w/, (c) => c.toUpperCase()),
          listingType:
            p.transaction_type === "rent"
              ? "For Rent"
              : p.transaction_type === "bnb"
                ? "BnB"
                : "For Sale",
          transaction_type: p.transaction_type,
          price: Number(p.price),
          currency: p.currency || "KES",
          bedrooms: p.bedrooms,
          bathrooms: p.bathrooms,
          size: p.square_feet ? `${p.square_feet} sq ft` : "Size n/a",
          squareFeet: p.square_feet || "",
          status: (p.availability_status || "available").replace(/^\w/, (c) => c.toUpperCase()),
          propertyStatus: p.property_status || "approved",
          location: {
            area: p.address || "",
            city: p.city || "",
            country: p.country || "Kenya",
            latitude: p.latitude,
            longitude: p.longitude,
          },
          primary_image_url: p.primary_image_url || null,
          images:
            Array.isArray(p.images) && p.images.length > 0
              ? p.images.map((img) => img.image_url || img)
              : p.primary_image_url
                ? [p.primary_image_url]
                : [],
          ownerId: p.owner_id,
          managerId: p.managed_by,
        }));
        setProperties(formatted);
      }

      if (isAdmin) {
        // Enquiries
        const enqRes = await getEnquiries();
        if (enqRes.success && enqRes.data?.data) {
          const formatted = enqRes.data.data.map((e) => ({
            id: e.id,
            name: e.client_name,
            email: e.client_email,
            phone: e.client_phone,
            status: (e.status || "NEW").replace(/^\w/, (c) => c.toUpperCase()),
            source: e.source || "Website",
            type: e.property_type || "Any",
            currency: e.currency || "KES",
            minPrice: e.min_price,
            maxPrice: e.max_price,
            city: e.preferred_city,
            notes: e.notes,
            createdAt: e.created_at,
          }));
          setEnquiries(formatted);
        }

        // Sales
        const salesRes = await getSales();
        if (salesRes.success && salesRes.data?.data) {
          const formatted = salesRes.data.data.map((s) => ({
            id: s.id,
            propertyId: s.property_id,
            propertyTitle: s.property_title || `Property #${s.property_id}`,
            buyerName: s.buyer_name,
            buyerEmail: s.buyer_email,
            salePrice: Number(s.sale_price),
            currency: s.currency || "KES",
            deposit: Number(s.deposit_amount),
            installmentAmount: Number(s.installment_amount),
            installmentsTotal: s.number_of_installments,
            nextDueDate: s.next_installment_due,
            status: s.sale_status,
            totalPaid: Number(s.total_paid || 0),
            balanceDue: Number(s.outstanding_balance || s.sale_price),
          }));
          setSales(formatted);
        }

        // Payments
        const payRes = await getAdminPayments();
        if (payRes.success && payRes.data?.data) {
          const formatted = payRes.data.data.map((p) => ({
            id: p.id,
            saleId: p.sale_id,
            tenancyId: p.tenancy_id,
            bookingId: p.booking_id,
            scope: p.transaction_scope || (p.tenancy_id ? "rental" : p.booking_id ? "bnb" : "sale"),
            paymentType: (p.payment_type || "payment").replace(/_/g, " "),
            reference: p.receipt_number || p.transaction_reference || `PAY-${p.id}`,
            buyerName: p.buyer_name || "Client",
            propertyTitle:
              p.property_title ||
              (p.sale_id
                ? `Sale #${p.sale_id}`
                : p.tenancy_id
                  ? `Tenancy #${p.tenancy_id}`
                  : `Booking #${p.booking_id}`),
            amount: Number(p.amount),
            currency: p.currency || "KES",
            method: (p.payment_method || "cash").replace("_", " "),
            status: p.payment_status,
            date: p.payment_date,
            createdAt: p.created_at || p.payment_date,
            recordedBy: p.recorded_by_name || p.recorded_by_email || "Administrator",
            note: p.transaction_reference || "",
          }));
          setPayments(formatted);
        }

        // Notifications
        const notifRes = await getNotificationLogs();
        if (notifRes.success && notifRes.data?.data) {
          const formatted = notifRes.data.data.map((n) => ({
            id: n.id,
            title: (n.notification_type || "Alert").replace(/_/g, " ").toUpperCase(),
            message: n.message,
            audience: n.recipient_name || n.recipient_contact,
            type: n.channel?.toUpperCase() || "EMAIL",
            channels: [n.channel || "email"],
            deliveryStatus: n.delivery_status,
            createdAt: n.sent_at || n.created_at,
          }));
          setNotifications(formatted);
        }

        // Users
        const usersRes = await getUsers();
        if (usersRes.success && usersRes.data) {
          const rawUsers =
            usersRes.data.users ||
            usersRes.data.data ||
            (Array.isArray(usersRes.data) ? usersRes.data : []);

          const mappedUsers = rawUsers.map((u) => ({
            id: u.id,
            name: u.full_name,
            full_name: u.full_name,
            email: u.email,
            role: u.role,
            phone: u.phone_number || "—",
            city: u.city || u.properties?.[0]?.city || "Nairobi",
            country: "Kenya",
            verified: u.account_status === "approved",
            status: u.account_status,
            account_status: u.account_status,
            created_at: u.created_at,
            owned_properties: u.owned_properties || [],
            managed_properties: u.managed_properties || [],
            properties: u.properties || [],
            property_counts: u.property_counts || {
              total: 0,
              owned: 0,
              managed: 0,
              active_listings: 0,
              pending_listings: 0,
              verified_owned: 0,
            },
            enquiries_count: u.enquiries_count || 0,
          }));

          setAllUsers(mappedUsers);
          setAgents(mappedUsers.filter((u) => u.role === "agent"));
          setOwners(mappedUsers.filter((u) => u.role === "owner"));
          setAdmins(
            rawUsers
              .filter((u) => u.role === "admin")
              .map((u) => ({
                id: u.id,
                name: u.full_name,
                email: u.email,
                status: u.account_status,
              }))
          );
        }

        // Deletion requests (Admin-only)
        const delRes = await getPendingDeletionRequests();
        if (delRes.success && delRes.data?.deletion_requests) {
          setDeletionRequests(delRes.data.deletion_requests);
        }
      }
    } catch (err) {
      console.error("Failed to load backend data:", err);
    } finally {
      setLoadingData(false);
    }
  }, [currentUser]);

  useEffect(() => {
    if (currentUser) {
      loadBackendData();
    }
  }, [currentUser, loadBackendData]);

  // Protected navigation & role filtering
  const userRole = currentUser?.role || "agent";
  const allowedPages = rolePages[userRole] || ["Dashboard", "Properties", "Enquiries"];

  function openNewPropertyEditor(defaults = {}) {
    const safeDefaults =
      defaults && typeof defaults === "object" && !defaults.nativeEvent && !defaults.target
        ? defaults
        : {};
    setEditingProperty(null);
    setPropertyDefaults(safeDefaults);
    setPropertyEditorOpen(true);
  }

  function openEditPropertyEditor(property) {
    setEditingProperty({
      id: property.id,
      title: property.title || "",
      type: property.type || "House",
      listingType: property.listingType || "For Sale",
      price: property.price || "",
      currency: property.currency === "KSH" ? "KES" : property.currency || "KES",
      country: property.location?.country || "Kenya",
      city: property.location?.city || "",
      area: property.location?.area || "",
      latitude: property.location?.latitude ?? "",
      longitude: property.location?.longitude ?? "",
      description: property.description || "",
      bedrooms: property.bedrooms ?? "",
      bathrooms: property.bathrooms ?? "",
      size: property.squareFeet || String(property.size || "").replace(/[^\d]/g, ""),
      status: property.status || "Available",
      images: property.images || [],
    });
    setPropertyDefaults({});
    setPropertyEditorOpen(true);
  }

  // Handlers for real API mutations
  async function handleSaveProperty(form) {
    const rawType = (form.type || "house").toLowerCase();
    const propertyType = rawType === "studio" ? "apartment" : rawType;
    const transactionType =
      form.listingType === "For Rent" ? "rent" : form.listingType === "BnB" ? "bnb" : "sale";
    const parsedSqFt = parseInt(String(form.size || "").replace(/[^\d]/g, ""), 10) || 0;
    const parsedPrice =
      Number(
        String(form.price ?? "")
          .replace(/,/g, "")
          .replace(/[^\d.]/g, "")
          .trim()
      ) || 0;
    const parsedBedrooms = Math.max(0, parseInt(String(form.bedrooms ?? "").replace(/[^\d]/g, ""), 10) || 0);
    const parsedBathrooms = Math.max(0, parseInt(String(form.bathrooms ?? "").replace(/[^\d]/g, ""), 10) || 0);
    const currency = form.currency === "KSH" ? "KES" : form.currency || "KES";

    const rawLat = String(form.latitude ?? "").trim();
    const rawLng = String(form.longitude ?? "").trim();
    const latNum = rawLat !== "" ? Number(rawLat) : NaN;
    const lngNum = rawLng !== "" ? Number(rawLng) : NaN;
    const hasValidCoords =
      Number.isFinite(latNum) &&
      Number.isFinite(lngNum) &&
      latNum >= -90 &&
      latNum <= 90 &&
      lngNum >= -180 &&
      lngNum <= 180;

    const payload = {
      title: sanitize(form.title),
      description: sanitize(form.description || ""),
      property_type: propertyType,
      transaction_type: transactionType,
      price: Number(parsedPrice.toFixed(2)),
      currency,
      availability_status: (form.status || "available").toLowerCase(),
      bedrooms: parsedBedrooms,
      bathrooms: parsedBathrooms,
      square_feet: parsedSqFt,
      address: sanitize(form.area || ""),
      city: sanitize(form.city || ""),
      country: sanitize(form.country || "Kenya"),
      latitude: hasValidCoords ? Number(latNum.toFixed(8)) : null,
      longitude: hasValidCoords ? Number(lngNum.toFixed(8)) : null,
    };

    async function uploadImagesForProperty(propertyId, images = [], existingImages = []) {
      if (!propertyId || !Array.isArray(images) || images.length === 0) return;
      for (let i = 0; i < images.length; i += 1) {
        const imgUrl = images[i];
        if (!imgUrl || existingImages.includes(imgUrl)) continue;
        await addPropertyImage(propertyId, {
          image_url: imgUrl,
          alt_text: sanitize(form.title || "Property photo"),
          is_primary: i === 0,
          sort_order: i,
        });
      }
    }

    async function uploadVerificationForProperty(propertyId, docFile) {
      if (!propertyId || !docFile) return;
      const reqRes = await requestPropertyVerification(propertyId);
      const verificationId = reqRes.data?.verification?.id;
      if (verificationId) {
        await uploadVerificationDocument(verificationId, docFile);
      }
    }

    if (editingProperty?.id) {
      const res = await updateProperty(editingProperty.id, payload);
      if (res.success) {
        await uploadImagesForProperty(editingProperty.id, form.images, editingProperty.images || []);
        await uploadVerificationForProperty(editingProperty.id, form.verificationDoc);
        notifyUser(
          userRole === "admin"
            ? "Property listing updated successfully!"
            : "Property updated and submitted for admin approval!"
        );
        setPropertyEditorOpen(false);
        setEditingProperty(null);
        await loadBackendData();
      } else {
        notifyUser(res.message || "Failed to update property.");
      }
      return res;
    }

    if (userRole === "admin") {
      if (form.ownerId) {
        payload.owner_id = Number(form.ownerId);
      }
      if (form.agentId) {
        payload.manager_id = Number(form.agentId);
      }
      if (form.agentId && !form.ownerId) {
        notifyUser("Select the owner on record before assigning a managing agent.");
        return { success: false };
      }
    }

    const res = await createProperty(payload);
    if (res.success) {
      const newPropertyId = res.data?.property?.id || res.data?.id;
      await uploadImagesForProperty(newPropertyId, form.images, []);
      await uploadVerificationForProperty(newPropertyId, form.verificationDoc);
      notifyUser(
        userRole === "admin"
          ? "Property saved and published! Registered agents notified."
          : "Property submitted for admin review and approval!"
      );
      setPropertyEditorOpen(false);
      setPropertyDefaults({});
      await loadBackendData();
    } else {
      notifyUser(res.message || "Failed to create property.");
    }
    return res;
  }

  async function handleUpdatePropertyStatus(propertyId, status) {
    const res = await updateProperty(propertyId, {
      availability_status: (status || "available").toLowerCase(),
    });
    if (res.success) {
      notifyUser(`Property status updated to ${status}.`);
      loadBackendData();
    } else {
      notifyUser(res.message || "Failed to update property status.");
    }
  }

  async function handleUpdateEnquiryStatus(enquiryId, status) {
    const normalized = String(status || "NEW").toUpperCase().replace(/\s+/g, "_");
    const res = await updateEnquiryStatus(enquiryId, normalized);
    if (res.success) {
      notifyUser(`Enquiry status updated to ${status}.`);
      loadBackendData();
    } else {
      notifyUser(res.message || "Failed to update enquiry status.");
    }
  }

  async function handleSaveEnquiry(form) {
    const rawType = (form.propertyType || form.type || "").toLowerCase();
    const payload = {
      client_name: sanitize(form.name),
      client_email: sanitize(form.email),
      client_phone: sanitize(form.phone || ""),
      property_type: rawType && rawType !== "any" ? (rawType === "studio" ? "apartment" : rawType) : null,
      min_price: form.budgetMin || form.minPrice ? Number(form.budgetMin || form.minPrice) : null,
      max_price: form.budgetMax || form.maxPrice ? Number(form.budgetMax || form.maxPrice) : null,
      currency: form.currency === "KSH" ? "KES" : form.currency || "KES",
      preferred_city: sanitize(form.city || form.location || ""),
      notes: sanitize(form.message || form.notes || ""),
      source: form.source || "Website",
    };

    const res = await createEnquiry(payload);
    if (res.success) {
      const matchCount = (res.data?.matches || []).length;
      notifyUser(`Enquiry registered! ${matchCount} properties automatically matched.`);
      loadBackendData();
    } else {
      notifyUser(res.message || "Failed to submit enquiry.");
    }
  }

  async function handleRecordPayment(form) {
    let targetInstallmentId = form.installmentId ? Number(form.installmentId) : null;
    let targetPaymentType = form.paymentType || null;

    if (!targetInstallmentId && !targetPaymentType) {
      const instRes = await getSaleInstallments(form.saleId);
      const instList =
        instRes.success && Array.isArray(instRes.data?.data) ? instRes.data.data : [];
      const nextUnpaid = instList.find(
        (inst) => Number(inst.outstanding_balance ?? inst.amount_due) > 0
      );
      if (nextUnpaid) {
        targetInstallmentId = Number(nextUnpaid.id);
        targetPaymentType = Number(nextUnpaid.installment_number) === 0 ? "deposit" : "installment";
      } else {
        targetPaymentType = "full";
      }
    }

    const requestedAmount = Number(form.amount);

    const payload = {
      amount: Number(requestedAmount.toFixed(2)),
      payment_method:
        form.method === "Bank transfer"
          ? "bank_transfer"
          : form.method === "M-Pesa"
            ? "mobile_money"
            : "cash",
      payment_type: targetPaymentType || "installment",
      ...(targetInstallmentId ? { installment_id: targetInstallmentId } : {}),
      ...(form.date && form.date.length === 10
        ? { payment_date: `${form.date} 12:00:00` }
        : {}),
      transaction_reference: form.note ? sanitize(form.note) : null,
      ...(form.confirmOverpayment ? { confirm_overpayment: true } : {}),
    };

    const res = await createPayment(form.saleId, payload);
    if (res.success) {
      notifyUser("Payment verified, confirmed, and official receipt issued!");
      await loadBackendData();
    } else {
      notifyUser(res.message || "Failed to record payment.");
    }
    return res;
  }

  async function handleAddSale(form) {
    const salePrice = Number(form.salePrice || 0);
    const depositAmount = Number(form.deposit || 0);
    const rawInstallments = Number(form.installmentsTotal ?? 0);
    const hasInstallments =
      rawInstallments > 0 && depositAmount > 0 && depositAmount < salePrice;

    const now = new Date();
    const localToday = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
    const scheduleStartDate =
      form.nextDueDate && form.nextDueDate >= localToday ? form.nextDueDate : localToday;

    const payload = {
      property_id: Number(form.propertyId),
      ...(form.enquiryId ? { enquiry_id: Number(form.enquiryId) } : {}),
      buyer_name: sanitize(form.buyerName),
      buyer_email: sanitize(form.buyerEmail),
      sale_price: Number(salePrice.toFixed(2)),
      currency: form.currency === "KSH" ? "KES" : form.currency || "KES",
      deposit_amount: hasInstallments ? Number(depositAmount.toFixed(2)) : 0,
      number_of_installments: hasInstallments ? rawInstallments : 0,
      ...(hasInstallments
        ? {
            installment_frequency: "monthly",
            schedule_start_date: scheduleStartDate,
          }
        : {}),
    };

    const res = await createSale(payload);
    if (res.success) {
      const newSaleId = res.data?.sale?.id || res.data?.id;
      const createdInstallments = Array.isArray(res.data?.installments)
        ? res.data.installments
        : [];

      if (newSaleId && depositAmount > 0) {
        const autoRef = `DEP-SALE-${newSaleId}-${Date.now().toString().slice(-6)}`;
        if (hasInstallments) {
          const depositInst = createdInstallments.find(
            (inst) => Number(inst.installment_number) === 0
          );
          if (depositInst) {
            await createPayment(newSaleId, {
              amount: Number(depositAmount.toFixed(2)),
              payment_type: "deposit",
              payment_method: "bank_transfer",
              installment_id: Number(depositInst.id),
              transaction_reference: autoRef,
            });
          }
        } else {
          await createPayment(newSaleId, {
            amount: Number(Math.min(depositAmount, salePrice).toFixed(2)),
            payment_type: "full",
            payment_method: "bank_transfer",
            transaction_reference: autoRef,
          });
        }
      }

      await updateProperty(Number(form.propertyId), {
        availability_status: "sold",
      });
      notifyUser("Sale registered and initial deposit receipted!");
      await loadBackendData();
    } else {
      notifyUser(res.message || "Failed to create sale account.");
    }
    return res;
  }

  async function handleSendReminder(sale) {
    const res = await sendInstallmentReminder(sale.id);
    if (res.success) {
      notifyUser(`Installment reminder dispatched via Email & SMS to ${sale.buyerName}`);
    } else {
      notifyUser(res.message || "Unable to send reminder.");
    }
  }

  async function handleInvitePerson(inviteData) {
    const res = await inviteUser(inviteData);
    if (res.success) {
      const notice = res.data?.mail_notice ? ` - ${res.data.mail_notice}` : "";
      notifyUser((res.message || `Registration form generated for ${inviteData.email}!`) + notice);
    } else {
      notifyUser(res.message || "Unable to send registration invite.");
    }
    return res;
  }

  async function handleCreateAdmin(form) {
    const res = await createAdminUser(form);
    if (res.success) {
      notifyUser(res.message || `Administrator account created for ${form.full_name}!`);
      await loadBackendData();
    } else {
      notifyUser(res.message || "Unable to create administrator account.");
    }
    return res;
  }

  async function handleSaveCompanyProfile(profileData) {
    const { logoFile, ...fields } = profileData;
    const profilePayload = fields.company_name ? fields : { company_name: fields.companyName || profileData.companyName, ...fields };
    const nameResult = await updateCompanyProfile(profilePayload);
    if (!nameResult.success || !nameResult.data?.profile) {
      return { success: false, message: nameResult.message || "Unable to update company profile." };
    }
    setCompanyProfile(nameResult.data.profile);

    if (logoFile) {
      const logoResult = await uploadCompanyLogo(logoFile);
      if (!logoResult.success || !logoResult.data?.profile) {
        return { success: false, message: logoResult.message || "Company profile details were saved, but the logo upload failed." };
      }
      setCompanyProfile(logoResult.data.profile);
    }

    notifyUser("Company profile updated successfully!");
    return { success: true };
  }

  async function handleUpdateUserVerification(id, action) {
    let res;
    if (action === "approve") {
      res = await approveUser(id);
    } else if (action === "reject") {
      res = await rejectUser(id);
    } else if (action === "suspend") {
      res = await suspendUser(id);
    } else if (action === "reactivate") {
      res = await reactivateUser(id);
    }

    if (res?.success) {
      notifyUser(res.message || "Account verification status updated.");
      loadBackendData();
    } else {
      notifyUser(res?.message || "Unable to update account status.");
    }
  }

  async function handleReviewDeletionRequest(id, action, notes = "") {
    let res;
    if (action === "approve") {
      res = await approveDeletionRequest(id, notes);
    } else {
      res = await rejectDeletionRequest(id, notes);
    }

    if (res?.success) {
      notifyUser(res.message || "Deletion request processed successfully.");
      loadBackendData();
    } else {
      notifyUser(res?.message || "Failed to process deletion request.");
    }
  }

  async function handleLogout() {
    await logout();
    setCurrentUser(null);
    setActivePage("Dashboard");
  }

  if (authLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[#f6f7f9]">
        <div className="h-10 w-10 animate-spin rounded-full border-4 border-[#0F2A43] border-t-transparent" />
      </div>
    );
  }

  // Not authenticated: render Auth View
  if (!currentUser) {
    return <AuthView onAuthSuccess={(user) => setCurrentUser(user)} />;
  }

  const userObj = {
    id: currentUser.id,
    name: currentUser.full_name || currentUser.name || "User",
    email: currentUser.email,
    role: currentUser.role,
  };

  return (
    <div className="min-h-screen bg-[#f6f7f9] text-slate-800 antialiased">
      <Sidebar
        activePage={activePage}
        setActivePage={(p) => {
          if (allowedPages.includes(p)) setActivePage(p);
        }}
        isOpen={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
        role={userRole}
      />

      <div className="flex min-h-screen flex-col md:pl-64">
        <Header
          onToggleSidebar={() => setSidebarOpen((prev) => !prev)}
          onOpenNotifications={() => setActivePage("Notifications")}
          notificationCount={notifications.length}
          user={userObj}
          onLogout={handleLogout}
          onNotify={notifyUser}
          companyProfile={companyProfile}
          onSaveCompanyProfile={handleSaveCompanyProfile}
        />

        <main className="flex-1 p-4 sm:p-6 lg:p-8">
          {toast && (
            <div
              role="status"
              aria-live="polite"
              className="fixed bottom-4 left-4 right-4 z-[60] rounded-2xl bg-[#0F2A43] px-5 py-4 text-sm font-semibold text-white shadow-2xl transition animate-bounce sm:bottom-6 sm:left-auto sm:right-6 sm:max-w-sm"
            >
              {toast}
            </div>
          )}

          {activePage === "Dashboard" && (
            <Dashboard
              properties={properties}
              enquiries={enquiries}
              agents={agents}
              sales={sales}
              payments={payments}
              rentals={[]}
              rentPayments={[]}
              notifications={notifications}
              onCreateAdmin={handleCreateAdmin}
              onNavigate={(page) => {
                if (allowedPages.includes(page)) setActivePage(page);
              }}
              onAddProperty={() => openNewPropertyEditor()}
              user={userObj}
            />
          )}

          {activePage === "Properties" && (
            <Properties
              properties={properties}
              enquiries={enquiries}
              agents={agents}
              user={userObj}
              onRequestAdd={() => openNewPropertyEditor()}
              onEditProperty={openEditPropertyEditor}
              onUpdateStatus={handleUpdatePropertyStatus}
              onSaveEnquiry={handleSaveEnquiry}
              onReload={loadBackendData}
              onToast={notifyUser}
            />
          )}

          {activePage === "Enquiries" && (
            <Enquiries
              enquiries={enquiries}
              properties={properties}
              onAddEnquiry={handleSaveEnquiry}
              onUpdateStatus={handleUpdateEnquiryStatus}
              onNotifyMatch={() => {}}
              readOnly={userRole !== "admin"}
            />
          )}

          {activePage === "Agents" && userRole === "admin" && (
            <People
              kind="Agents"
              people={agents}
              allUsers={allUsers}
              admins={admins}
              deletionRequests={deletionRequests}
              onUpdateVerification={handleUpdateUserVerification}
              onReviewDeletionRequest={handleReviewDeletionRequest}
              onInvitePerson={handleInvitePerson}
              onListProperty={(kind, person) =>
                openNewPropertyEditor({
                  agentId: String(person.id),
                })
              }
            />
          )}

          {activePage === "Owners" && userRole === "admin" && (
            <People
              kind="Owners"
              people={owners}
              allUsers={allUsers}
              admins={admins}
              deletionRequests={deletionRequests}
              onUpdateVerification={handleUpdateUserVerification}
              onReviewDeletionRequest={handleReviewDeletionRequest}
              onInvitePerson={handleInvitePerson}
              onListProperty={(kind, person) =>
                openNewPropertyEditor({
                  ownerId: String(person.id),
                })
              }
            />
          )}

          {activePage === "Sales" && (userRole === "admin" || userRole === "agent") && (
            <Sales
              sales={sales}
              properties={properties}
              enquiries={enquiries}
              payments={payments}
              companyProfile={companyProfile}
              onAddSale={handleAddSale}
              onSendReminder={handleSendReminder}
              onGoPayments={(saleId) => {
                setHighlightedSaleId(saleId || null);
                setActivePage("Payments");
              }}
              canRecordPayment={userRole === "admin"}
              canViewPaymentHistory={userRole === "admin"}
            />
          )}

          {activePage === "Payments" && userRole === "admin" && (
            <Payments
              payments={payments}
              sales={sales}
              user={userObj}
              onRecordPayment={handleRecordPayment}
              onSendReminder={handleSendReminder}
              highlightedSaleId={highlightedSaleId}
              companyProfile={companyProfile}
            />
          )}

          {activePage === "Rentals" && (
            <Rentals
              properties={properties}
              user={userObj}
              companyProfile={companyProfile}
              onToast={notifyUser}
              onReload={loadBackendData}
              onAddProperty={openNewPropertyEditor}
              canManage={userRole === "admin" || userRole === "agent"}
            />
          )}

          {activePage === "Notifications" && (
            <Notifications
              notifications={notifications}
              user={userObj}
            />
          )}

          {propertyEditorOpen && (
            <PropertyEditor
              agents={agents}
              owners={owners}
              userRole={userRole}
              initialValues={editingProperty || propertyDefaults}
              isEditing={Boolean(editingProperty)}
              onClose={() => {
                setPropertyEditorOpen(false);
                setEditingProperty(null);
                setPropertyDefaults({});
              }}
              onSave={handleSaveProperty}
            />
          )}
        </main>
      </div>
    </div>
  );
}
