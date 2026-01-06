<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\GeneralNotification;

class NotificationController extends Controller
{
    /**
     * Display a list of user notifications
     */
    public function index()
    {
        try {
            $userId = Auth::id();
            
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated',
                    'data' => []
                ], 401);
            }

            $notifications = GeneralNotification::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            // Handle case where is_read column might not exist
            $unreadCount = 0;
            try {
                $unreadCount = $notifications->where('is_read', false)->count();
            } catch (\Exception $e) {
                // If is_read doesn't exist, count all as unread or none
                $unreadCount = 0;
            }

            return response()->json([
                'status' => 'success',
                'data' => $notifications,
                'unread_count' => $unreadCount
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch notifications: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch notifications',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
                'data' => []
            ], 500);
        }
    }

    /**
     * Mark a specific notification as read
     */
    public function markAsRead($id)
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            $notification = GeneralNotification::where('user_id', $userId)->findOrFail($id);
            $notification->update(['is_read' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'Notification marked as read.'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark notification as read: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark notification as read'
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            GeneralNotification::where('user_id', $userId)->update(['is_read' => true]);

            return response()->json([
                'status' => 'success',
                'message' => 'All notifications marked as read.'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark all notifications as read'
            ], 500);
        }
    }

    /**
     * Delete a specific notification
     */
    public function destroy($id)
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            $notification = GeneralNotification::where('user_id', $userId)->findOrFail($id);
            $notification->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Notification deleted successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete notification: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete notification'
            ], 500);
        }
    }

    /**
     * Delete all notifications
     */
    public function clearAll()
    {
        try {
            $userId = Auth::id();
            if (!$userId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not authenticated'
                ], 401);
            }

            GeneralNotification::where('user_id', $userId)->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'All notifications cleared.'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear all notifications: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear all notifications'
            ], 500);
        }
    }
}
